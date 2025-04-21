<?php

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Database\Capsule;

$month = isset($_POST['month']) ? (int)$_POST['month'] : date('n');
$year = isset($_POST['year']) ? (int)$_POST['year'] : date('Y');
$export = isset($_POST['export']) ? true : false;

$startDate = "$year-" . str_pad($month, 2, "0", STR_PAD_LEFT) . "-01";
$endDate = date("Y-m-t", strtotime($startDate));

$items = Capsule::table('tblinvoiceitems')
    ->join('tblinvoices', 'tblinvoiceitems.invoiceid', '=', 'tblinvoices.id')
    ->leftJoin(
        Capsule::raw('(SELECT invoiceid, MAX(id) as maxid FROM tblaccounts GROUP BY invoiceid) as latest_tx'),
        'tblinvoices.id',
        '=',
        'latest_tx.invoiceid'
    )
    ->leftJoin('tblaccounts', 'tblaccounts.id', '=', 'latest_tx.maxid')
    ->where('tblinvoices.status', 'Paid')
    ->whereBetween('tblinvoices.date', [$startDate, $endDate])
    ->orderBy('tblinvoices.date', 'asc')
    ->get([
        'tblinvoiceitems.description',
        'tblinvoiceitems.amount',
        'tblinvoices.date',
        'tblinvoiceitems.relid',
        'tblinvoiceitems.type',
        'tblinvoices.id AS invoice_id',
        'tblaccounts.transid',
        'tblaccounts.gateway AS paymentmethod'
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

    $data[] = [
        $item->invoice_id,
        $item->description,
        $domainName,
        date("d-m-Y", strtotime($item->date)),
        $item->paymentmethod ?: '-',
        $item->transid ?: '-',
        formatCurrency($item->amount)
    ];
}

if ($export) {
    ob_end_clean();

    header("Content-Type: application/vnd.ms-excel");
    header("Content-Disposition: attachment; filename=\"laporan_transaksi_paid_{$month}_{$year}.xls\"");

    echo "<html><head><meta charset=\"UTF-8\"></head><body>";
    echo "<table border=\"1\">";
    echo "<tr>
            <th>Invoice</th>
            <th>Nama Layanan</th>
            <th>Nama Domain</th>
            <th>Tanggal Transaksi</th>
            <th>Payment Method</th>
            <th>Transaction ID</th>
            <th>Nominal Transaksi</th>
          </tr>";

    foreach ($data as $row) {
        echo "<tr>";
        foreach ($row as $i => $col) {
            if ($i === 6) {
                $numeric = preg_replace('/[^\d.]/', '', str_replace(',', '.', $col));
                echo "<td>" . number_format((float)$numeric, 2, '.', '') . "</td>";
            } else {
                echo "<td>" . htmlspecialchars($col) . "</td>";
            }
        }
        echo "</tr>";
    }

    echo "<tr><td><strong>Total</strong></td><td></td><td></td><td></td><td></td><td></td><td><strong>" . number_format($totalAmount, 2, '.', '') . "</strong></td></tr>";
    echo "</table></body></html>";
    exit;
}

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
    "Invoice",
    "Nama Layanan",
    "Nama Domain",
    "Tanggal Transaksi",
    "Payment Method",
    "Transaction ID",
    "Nominal Transaksi"
];

foreach ($data as $row) {
    $reportdata["tablevalues"][] = $row;
}

$reportdata["tablevalues"][] = [
    "<strong>Total</strong>",
    "",
    "",
    "",
    "",
    "",
    "<strong>" . formatCurrency($totalAmount) . "</strong>"
];
