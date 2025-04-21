<?php

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Database\Capsule;

// Tangkap filter dan cek jika sedang request export
$month = isset($_POST['month']) ? (int)$_POST['month'] : date('n');
$year = isset($_POST['year']) ? (int)$_POST['year'] : date('Y');
$export = isset($_POST['export']) ? true : false;

$startDate = "$year-" . str_pad($month, 2, "0", STR_PAD_LEFT) . "-01";
$endDate = date("Y-m-t", strtotime($startDate));

// Ambil data invoice items
$items = Capsule::table('tblinvoiceitems')
    ->join('tblinvoices', 'tblinvoiceitems.invoiceid', '=', 'tblinvoices.id')
    ->where('tblinvoices.status', 'Paid')
    ->whereBetween('tblinvoices.date', [$startDate, $endDate])
    ->orderBy('tblinvoices.date', 'asc')
    ->get([
        'tblinvoiceitems.description',
        'tblinvoiceitems.amount',
        'tblinvoices.date',
        'tblinvoiceitems.relid',
        'tblinvoiceitems.type',
        'tblinvoices.id AS invoice_id' // Menambahkan ID invoice
    ]);

$data = [];
$totalAmount = 0;

foreach ($items as $item) {
    $domainName = '';
    if ($item->type === 'Domain') {
        $domain = Capsule::table('tbldomains')->where('id', $item->relid)->first();
        $domainName = $domain ? $domain->domain : '';
    } elseif ($item->type === 'Hosting') {
        $hosting = Capsule::table('tblhosting')->where('id', $item->relid)->first();
        $domainName = $hosting ? $hosting->domain : '';
    }

    $totalAmount += $item->amount;

    // Data yang akan ditampilkan di UI WHMCS (Format mata uang)
    $data[] = [
        $item->invoice_id, // Menambahkan nomor invoice di posisi pertama
        $item->description,
        $domainName,
        date("d-m-Y", strtotime($item->date)),
        formatCurrency($item->amount) // Format mata uang untuk UI
    ];
}

// Tangani export ke Excel
if ($export) {
    // Membersihkan output sebelumnya jika ada
    ob_end_clean(); 

    // Mengatur header untuk export Excel
    header("Content-Type: application/vnd.ms-excel");
    header("Content-Disposition: attachment; filename=\"laporan_transaksi_paid_{$month}_{$year}.xls\"");

    echo "<html><head><meta charset=\"UTF-8\"></head><body>";
    echo "<table border=\"1\">";
    echo "<tr><th>Invoice</th><th>Nama Layanan</th><th>Nama Domain</th><th>Tanggal Transaksi</th><th>Nominal Transaksi</th></tr>";

    // Isi data untuk export ke Excel (Nominal Transaksi berupa angka mentah)
    foreach ($data as $row) {
        echo "<tr>";
        echo "<td>" . htmlspecialchars($row[0]) . "</td>";
        echo "<td>" . htmlspecialchars($row[1]) . "</td>";
        echo "<td>" . htmlspecialchars($row[2]) . "</td>";
        echo "<td>" . $row[3] . "</td>";
        // Menggunakan angka mentah (tanpa simbol mata uang) untuk export
        echo "<td>" . number_format(str_replace(['Rp', '.', ','], '', $row[4]), 2, '.', '') . "</td>";
        echo "</tr>";
    }

    // Total baris di Excel
    echo "<tr><td><strong>Total</strong></td><td></td><td></td><td></td><td><strong>" . number_format(str_replace(['Rp', '.', ','], '', formatCurrency($totalAmount)), 2, '.', '') . "</strong></td></tr>";
    echo "</table></body></html>";
    exit;
}

// Jika bukan export, tampilkan di UI WHMCS
$reportdata["title"] = "Laporan Transaksi Paid per Bulan";

$reportdata["headertext"] = "
<form method=\"post\">
    <label>Bulan:
        <select name=\"month\">
            " . implode('', array_map(fn($m) => "<option value=\"$m\"" . ($m == $month ? " selected" : "") . ">" . date("F", mktime(0, 0, 0, $m, 1)) . "</option>", range(1, 12))) . "
        </select>
    </label>
    <label>Tahun:
        <input type=\"number\" name=\"year\" value=\"$year\" min=\"2000\" max=\"2100\">
    </label>
    <input type=\"submit\" value=\"Tampilkan\">
    <button type=\"submit\" name=\"export\" value=\"1\">Export ke Excel</button>
</form>
";

$reportdata["tableheadings"] = [
    "Invoice", // Kolom baru untuk Nomor Invoice
    "Nama Layanan",
    "Nama Domain",
    "Tanggal Transaksi",
    "Nominal Transaksi"
];

// Tambahkan data ke laporan
foreach ($data as $row) {
    $reportdata["tablevalues"][] = $row;
}

// Tambahkan baris total
$reportdata["tablevalues"][] = [
    "<strong>Total</strong>",
    "",
    "",
    "",
    "<strong>" . formatCurrency($totalAmount) . "</strong>"
];
