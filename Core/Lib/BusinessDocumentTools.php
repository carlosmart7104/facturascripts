<?php
/**
 * This file is part of FacturaScripts
 * Copyright (C) 2018 Carlos Garcia Gomez <carlos@facturascripts.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */
namespace FacturaScripts\Core\Lib;

use FacturaScripts\Core\Base\Utils;
use FacturaScripts\Core\Model\Base\BusinessDocument;
use FacturaScripts\Core\Model\Base\BusinessDocumentLine;
use FacturaScripts\Dinamic\Model\Cliente;
use FacturaScripts\Dinamic\Model\Empresa;
use FacturaScripts\Dinamic\Model\Proveedor;
use FacturaScripts\Dinamic\Model\Serie;

/**
 * Description of DocumentCalculator
 *
 * @author Carlos García Gómez <carlos@facturascripts.com>
 */
class BusinessDocumentTools
{

    /**
     *
     * @var bool
     */
    private $recargo = false;

    /**
     *
     * @var bool
     */
    private $siniva = false;

    /**
     * Returns subtotals by tax.
     *
     * @param BusinessDocumentLine[] $lines
     *
     * @return array
     */
    public function getSubtotals($lines)
    {
        $irpf = 0.0;
        $subtotals = [];
        $totalIrpf = 0.0;
        foreach ($lines as $line) {
            $codimpuesto = empty($line->codimpuesto) ? $line->iva . '-' . $line->recargo : $line->codimpuesto;
            if (!array_key_exists($codimpuesto, $subtotals)) {
                $subtotals[$codimpuesto] = [
                    'irpf' => 0.0,
                    'neto' => 0.0,
                    'totaliva' => 0.0,
                    'totalrecargo' => 0.0,
                    'totalirpf' => 0.0,
                ];
            }

            $irpf = max([$irpf, $line->irpf]);
            $subtotals[$codimpuesto]['neto'] += $line->pvptotal;
            $subtotals[$codimpuesto]['totaliva'] += $line->pvptotal * $line->iva / 100;
            $subtotals[$codimpuesto]['totalrecargo'] += $line->pvptotal * $line->recargo / 100;
            $totalIrpf += $line->pvptotal * $line->irpf / 100;
        }

        /// IRPF to the first subtotal
        foreach ($subtotals as $key => $value) {
            $subtotals[$key]['irpf'] = $irpf;
            $subtotals[$key]['totalirpf'] = $totalIrpf;
            break;
        }

        /// rounding totals
        foreach ($subtotals as $key => $value) {
            $subtotals[$key]['neto'] = round($value['neto'], (int) FS_NF0);
            $subtotals[$key]['totaliva'] = round($value['totaliva'], (int) FS_NF0);
            $subtotals[$key]['totalrecargo'] = round($value['totalrecargo'], (int) FS_NF0);
            $subtotals[$key]['totalirpf'] = round($value['totalirpf'], (int) FS_NF0);
        }

        return $subtotals;
    }

    /**
     * Recalculates document totals.
     *
     * @param BusinessDocument $doc
     */
    public function recalculate(BusinessDocument &$doc)
    {
        $this->clearTotals($doc);
        $lines = $doc->getLines();
        foreach ($this->getSubtotals($lines) as $subt) {
            $doc->neto += $subt['neto'];
            $doc->totaliva += $subt['totaliva'];
            $doc->totalirpf += $subt['totalirpf'];
            $doc->totalrecargo += $subt['totalrecargo'];
        }

        $doc->total = round($doc->neto + $doc->totaliva + $doc->totalrecargo - $doc->totalirpf, (int) FS_NF0);
    }

    /**
     * Calculate document totals from form data and returns the new total and document lines.
     *
     * @param BusinessDocument $doc
     * @param array            $formLines
     *
     * @return string
     */
    public function recalculateForm(BusinessDocument &$doc, array &$formLines)
    {
        $this->clearTotals($doc);
        $lines = [];
        foreach ($formLines as $fLine) {
            $lines[] = $this->recalculateLine($fLine, $doc);
        }

        foreach ($this->getSubtotals($lines) as $subt) {
            $doc->neto += $subt['neto'];
            $doc->totaliva += $subt['totaliva'];
            $doc->totalirpf += $subt['totalirpf'];
            $doc->totalrecargo += $subt['totalrecargo'];
        }

        $doc->total = round($doc->neto + $doc->totaliva + $doc->totalrecargo - $doc->totalirpf, (int) FS_NF0);
        $json = [
            'total' => $doc->total,
            'lines' => $lines
        ];

        return json_encode($json);
    }

    /**
     * 
     * @param BusinessDocument $doc
     */
    private function clearTotals(BusinessDocument &$doc)
    {
        $doc->neto = 0.0;
        $doc->total = 0.0;
        $doc->totaleuros = 0.0;
        $doc->totaliva = 0.0;
        $doc->totalirpf = 0.0;
        $doc->totalrecargo = 0.0;

        $serie = new Serie();
        if ($serie->loadFromCode($doc->codserie)) {
            $this->siniva = $serie->siniva;
        }

        if ($doc->exists()) {
            return;
        }

        if (isset($doc->codcliente)) {
            $cliente = new Cliente();
            if ($cliente->loadFromCode($doc->codcliente)) {
                $doc->irpf = $cliente->irpf;
                $this->loadRegimenIva($cliente->regimeniva);
            }
        } elseif (isset($doc->codproveedor)) {
            $proveedor = new Proveedor();
            if ($proveedor->loadFromCode($doc->codproveedor)) {
                $doc->irpf = $proveedor->irpf;
                $this->loadRegimenIva($proveedor->regimeniva);
            }

            $empresa = new Empresa();
            if ($empresa->loadFromCode($doc->idempresa)) {
                $this->loadRegimenIva($empresa->regimeniva);
            }
        }
    }

    /**
     * 
     * @param string $reg
     */
    private function loadRegimenIva($reg)
    {
        switch ($reg) {
            case 'Exento':
                $this->siniva = true;
                break;

            case 'Recargo':
                $this->recargo = true;
                break;
        }
    }

    /**
     * 
     * @param array            $fLine
     * @param BusinessDocument $doc
     *
     * @return BusinessDocumentLine
     */
    private function recalculateLine(array $fLine, BusinessDocument $doc)
    {
        if (!isset($fLine['cantidad']) || '' === $fLine['cantidad']) {
            $newLine = $doc->getNewProductLine($fLine['referencia']);
        } else {
            $newLine = $doc->getNewLine();
            foreach ($fLine as $key => $value) {
                $newLine->{$key} = $value;
            }
            $newLine->cantidad = (float) $fLine['cantidad'];
            $newLine->pvpunitario = (float) $fLine['pvpunitario'];
            $newLine->dtopor = (float) $fLine['dtopor'];
            $newLine->irpf = (float) $fLine['irpf'];
            $newLine->iva = (float) $fLine['iva'];
            $newLine->recargo = (float) $fLine['recargo'];
        }

        $newLine->descripcion = Utils::fixHtml($newLine->descripcion);
        $newLine->pvpsindto = $newLine->pvpunitario * $newLine->cantidad;
        $newLine->pvptotal = $newLine->pvpsindto * (100 - $newLine->dtopor) / 100;

        if ($this->siniva) {
            $newLine->irpf = $newLine->iva = $newLine->recargo = 0.0;
        } elseif (!$this->recargo) {
            $newLine->recargo = 0.0;
        }

        return $newLine;
    }
}
