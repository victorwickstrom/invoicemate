<?php
namespace Invoicemate\Controllers;

use PDO;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;

class ReportController
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Generate VAT report for a period.
     * Endpoint: GET /v1/{org}/reports/vat?from=YYYY-MM-DD&to=YYYY-MM-DD
     */
    public function vatReport(Request $request, Response $response, array $args): Response
    {
        $orgId = (int)$args['organizationId'];
        $params = $request->getQueryParams();
        $from = $params['from'] ?? null;
        $to = $params['to'] ?? null;
        if (!$from || !$to) {
            return $this->json($response, ['error' => 'Missing date range'], 400);
        }
        // Query helper
        $sum = function (string $where) use ($orgId, $from, $to) {
            $stmt = $this->pdo->prepare("SELECT COALESCE(SUM(vat_amount), 0) AS vat_sum FROM ledger_vat WHERE organization_id = :org AND date BETWEEN :from AND :to AND $where");
            $stmt->execute([':org' => $orgId, ':from' => $from, ':to' => $to]);
            return (float)$stmt->fetchColumn();
        };
        $sumNet = function (string $where) use ($orgId, $from, $to) {
            $stmt = $this->pdo->prepare("SELECT COALESCE(SUM(net_amount), 0) AS net_sum FROM ledger_vat WHERE organization_id = :org AND date BETWEEN :from AND :to AND $where");
            $stmt->execute([':org' => $orgId, ':from' => $from, ':to' => $to]);
            return (float)$stmt->fetchColumn();
        };

        $salesVat = $sum("direction = 'output'");
        $purchaseVat = $sum("direction = 'input'");
        $euGoodsBase = $sumNet("direction = 'reverse_output' AND movement = 'eu_goods'");
        $euGoodsVat = $sum("direction = 'reverse_output' AND movement = 'eu_goods'");
        $euServicesBase = $sumNet("direction = 'reverse_output' AND movement = 'eu_services'");
        $euServicesVat = $sum("direction = 'reverse_output' AND movement = 'eu_services'");
        $euSalesGoods = $sumNet("direction = 'output' AND movement = 'eu_goods' AND vat_amount = 0");
        $euSalesServices = $sumNet("direction = 'output' AND movement = 'eu_services' AND vat_amount = 0");
        $euPurchGoods = $sumNet("movement = 'eu_goods'");
        $euPurchServices = $sumNet("movement = 'eu_services'");

        // Net VAT payable: sales_vat - purchase_vat + vat_on_eu_purchases (goods + services) - deductible reverse charge input
        $netVat = $salesVat - $purchaseVat + $euGoodsVat + $euServicesVat;
        $data = [
            'period' => ['from' => $from, 'to' => $to],
            'sales_vat' => round($salesVat, 2),
            'purchase_vat' => round($purchaseVat, 2),
            'vat_on_eu_goods_purchases' => ['base' => round($euGoodsBase, 2), 'vat' => round($euGoodsVat, 2)],
            'vat_on_eu_services_purchases' => ['base' => round($euServicesBase, 2), 'vat' => round($euServicesVat, 2)],
            'eu_sales_ex_vat_goods' => round($euSalesGoods, 2),
            'eu_sales_ex_vat_services' => round($euSalesServices, 2),
            'eu_purchases_value_goods' => round($euPurchGoods, 2),
            'eu_purchases_value_services' => round($euPurchServices, 2),
            'net_vat_payable' => round($netVat, 2),
            'notes' => ['Reverse charge VAT is counted as both output and input VAT where deductible'],
        ];
        return $this->json($response, $data);
    }

    private function json(Response $response, $data, int $status = 200): Response
    {
        $response->getBody()->rewind();
        $response->getBody()->write(json_encode($data));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}