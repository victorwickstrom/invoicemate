<?php

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;

return function (App $app) {
    // Hent liste over danske momstyper
    $app->get('/v1/{organizationId}/vatTypes', function (Request $request, Response $response) {
        $vatTypes = [
            ["vatCode" => "U25", "name" => "Dansk Salgsmoms", "vatRate" => 0.25],
            ["vatCode" => "UEUV", "name" => "Varesalg EU – Indberettes (rubrik B- varer)", "vatRate" => 0.00],
            ["vatCode" => "UEUV2", "name" => "Varesalg EU – Indberettes ikke (rubrik B- varer)", "vatRate" => 0.00],
            ["vatCode" => "UEUY", "name" => "Ydelsessalg EU (rubrik B- ydelser)", "vatRate" => 0.00],
            ["vatCode" => "UVC", "name" => "Salg til verden (rubrik C)", "vatRate" => 0.00],
            ["vatCode" => "KUNS", "name" => "Kunstnermoms", "vatRate" => 0.05],
            ["vatCode" => "OBPS", "name" => "Dansk salg med omvendt betalingspligt", "vatRate" => 0.00],
            ["vatCode" => "I25", "name" => "Dansk købsmoms", "vatRate" => 0.25],
            ["vatCode" => "IEUV", "name" => "Varekøb EU (rubrik A- varer)", "vatRate" => 0.00],
            ["vatCode" => "IEUY", "name" => "Ydelseskøb EU (rubrik A- ydelser)", "vatRate" => 0.00],
            ["vatCode" => "IVV", "name" => "Varekøb fra verden", "vatRate" => 0.00],
            ["vatCode" => "IVY", "name" => "Ydelseskøb fra verden", "vatRate" => 0.00],
            ["vatCode" => "HREP", "name" => "Hotel (trekvartmoms)", "vatRate" => 0.1875],
            ["vatCode" => "REP", "name" => "Repræsentation (kvartmoms)", "vatRate" => 0.0625],
            ["vatCode" => "OBPK", "name" => "Dansk køb med omvendt betalingspligt", "vatRate" => 0.00]
        ];

        $response->getBody()->write(json_encode($vatTypes));
        return $response->withHeader('Content-Type', 'application/json');
    });
};
