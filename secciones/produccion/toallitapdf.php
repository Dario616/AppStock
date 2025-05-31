<?php
ini_set('memory_limit', '512M');
require_once "../../conexionBD.php";
include "../../vendor/tecnickcom/tcpdf/tcpdf.php";

if (!isset($_GET['id_orden']) || empty($_GET['id_orden'])) {
    header("Location: ./registro_stock.php?error=ID de orden no especificado");
    exit();
}

$id_orden = (int)$_GET['id_orden'];
$id_stock_especifico = isset($_GET['id_stock']) ? (int)$_GET['id_stock'] : null;

try {
    // Obtener datos de la orden de producción específica para toallitas
    $query_orden = "SELECT op.id, op.id_venta, op.fecha_orden, op.estado, op.observaciones,
                    v.cliente, v.id as id_venta,
                    prod.descripcion, prod.tipoproducto, prod.unidadmedida,
                    
                    -- Datos específicos de Toallitas (solo campos que existen)
                    toal.nombre as nombre_toallitas, 
                    toal.cantidad_total as cantidad_toallitas,
                    
                    -- Información adicional para la etiqueta
                    TO_CHAR(CURRENT_DATE, 'DD/MM/YYYY') as fecha_fabricacion,
                    CONCAT('LOTE:', LPAD(op.id::text, 5, '0')) as numero_lote
                    
                    FROM public.sist_ventas_orden_produccion op
                    LEFT JOIN public.sist_ventas_presupuesto v ON op.id_venta = v.id
                    LEFT JOIN public.sist_ventas_op_toallitas toal ON toal.id_orden_produccion = op.id
                    LEFT JOIN public.sist_ventas_pres_product prod ON toal.id_producto = prod.id
                    WHERE op.id = :id_orden AND toal.id IS NOT NULL";

    $stmt_orden = $conexion->prepare($query_orden);
    $stmt_orden->bindParam(':id_orden', $id_orden, PDO::PARAM_INT);
    $stmt_orden->execute();
    $orden = $stmt_orden->fetch(PDO::FETCH_ASSOC);

    if (!$orden) {
        header("Location: ./registro_stock.php?error=Orden de toallitas no encontrada");
        exit();
    }

    // ⭐ NUEVA LÓGICA: Obtener registro específico o último
    if ($id_stock_especifico) {
        // Reimprimir registro específico
        $query_stock = "SELECT numero_item, numero_etiqueta, id
                        FROM public.sist_prod_stock 
                        WHERE id = :id_stock AND id_orden_produccion = :id_orden";
        
        $stmt_stock = $conexion->prepare($query_stock);
        $stmt_stock->bindParam(':id_stock', $id_stock_especifico, PDO::PARAM_INT);
        $stmt_stock->bindParam(':id_orden', $id_orden, PDO::PARAM_INT);
        $stmt_stock->execute();
        $stock_data = $stmt_stock->fetch(PDO::FETCH_ASSOC);
        
        // Verificar que el registro existe
        if (!$stock_data) {
            header("Location: ./registro_stock.php?error=Registro de stock específico no encontrado");
            exit();
        }
        
        // Debug para verificar
        error_log("🎯 Reimprimiendo registro específico - ID: " . $id_stock_especifico . ", Item: " . $stock_data['numero_item']);
        
    } else {
        // Usar el último registro (comportamiento original para nuevos registros)
        $query_stock = "SELECT numero_item, numero_etiqueta, id
                        FROM public.sist_prod_stock 
                        WHERE id_orden_produccion = :id_orden 
                        ORDER BY created_at DESC
                        LIMIT 1";
        
        $stmt_stock = $conexion->prepare($query_stock);
        $stmt_stock->bindParam(':id_orden', $id_orden, PDO::PARAM_INT);
        $stmt_stock->execute();
        $stock_data = $stmt_stock->fetch(PDO::FETCH_ASSOC);
        
        // Debug para verificar
        if ($stock_data) {
            error_log("📦 Usando último registro - ID: " . $stock_data['id'] . ", Item: " . $stock_data['numero_item']);
        }
    }
    
    // Convertir numero_item a string para código de barras
    $numero_item_original = null;
    if ($stock_data && isset($stock_data['numero_item'])) {
        // Guardar el valor original para mostrar
        $numero_item_original = $stock_data['numero_item'];
        // Usar numero_item directamente (es integer NOT NULL)
        $numero_item = str_pad($stock_data['numero_item'], 12, '0', STR_PAD_LEFT);
    } else {
        // Si no existe registro en stock, generar código basado en el ID de orden
        $numero_item = 'ORD' . str_pad($id_orden, 9, '0', STR_PAD_LEFT);
    }
    
    // Debug final - verificar el valor que se usará
    error_log("🏷️ Código de barras final: " . $numero_item . " (Original: " . $numero_item_original . ") para orden: " . $id_orden);

    // Extraer unidades y dimensiones del nombre del producto
    $nombre_producto = $orden['nombre_toallitas'];
    
    // Extraer unidades (buscar número seguido de "Unidades")
    $unidades = 100; // Valor por defecto
    if (preg_match('/(\d+)\s*Unidades/i', $nombre_producto, $matches_unidades)) {
        $unidades = (int)$matches_unidades[1];
    }
    
    // Extraer dimensiones (buscar patrón como "20cm x 15cm" o "20 x 15cm")
    $dimensiones = '20X15CM'; // Valor por defecto
    if (preg_match('/(\d+)\s*cm?\s*x\s*(\d+)\s*cm?/i', $nombre_producto, $matches_dimensiones)) {
        $dimensiones = $matches_dimensiones[1] . 'X' . $matches_dimensiones[2] . 'CM';
    }
    
    // Configurar paquetes y código de barras según dimensiones y unidades
    $paquetes = 16; // Valor por defecto
    $barcode_data = '0189445136046572'; // Valor por defecto
    
    // Si es 20x10 con 20 unidades, cambiar valores específicos
    if ($dimensiones == '20X10CM' && $unidades == 20) {
        $paquetes = 44;
        $barcode_data = '0189445136046404';
    }

    // Crear una clase personalizada de TCPDF
    class EtiquetaPDF extends TCPDF
    {
        public function Header() {}
        public function Footer() {}
    }

    // Crear un nuevo documento PDF - 10x8cm
    $pdf = new EtiquetaPDF('L', 'mm', array(100, 80), true, 'UTF-8', false);

    // Establecer información del documento
    $pdf->SetCreator('America TNT S.A.');
    $pdf->SetAuthor('America TNT S.A.');
    $pdf->SetTitle('Etiqueta Cottonfresh - Toallitas Húmedas');
    $pdf->SetSubject('Etiqueta de Producto');

    // Sin márgenes para etiqueta completa
    $pdf->SetMargins(0, 0, 0);
    $pdf->SetHeaderMargin(0);
    $pdf->SetFooterMargin(0);
    $pdf->SetAutoPageBreak(false);

    // Añadir una página
    $pdf->AddPage();

    // Borde negro grueso alrededor de toda la etiqueta
    $pdf->SetLineWidth(1);
    $pdf->Rect(1, 1, 98, 78, 'D');

    // Logo Cottonfresh
    $cottonfreshLogoPath = '../../utils/cotton.jpeg';
    if (file_exists($cottonfreshLogoPath)) {
        // Logo ajustado para 10x8cm
        $pdf->Image($cottonfreshLogoPath, 2, 3, 62, 15, '', '', '', true, 300, '', false, false, 0, false, false, false);
    } else {
        // Si no existe el logo, usar texto como respaldo
        $pdf->SetFont('helvetica', 'B', 16);
        $pdf->SetXY(2, 8);
        $pdf->Cell(60, 8, 'Cottonfresh', 0, 0, 'L');
    }

    // Descripción principal del producto - Línea 1
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->SetXY(2, 18);
    $pdf->Cell(20, 4, 'CONTIENE ', 0, 0, 'L');

    // Número de paquetes - MÁS GRANDE
        $pdf->SetXY(23, 18);
    $pdf->SetFont('helvetica', 'B', 13);
    $pdf->Cell(8, 4, $paquetes, 0, 0, 'C');

    // Resto de la línea 1
       $pdf->SetXY(29, 18);
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->Cell(25, 4, ' PAQUETES DE', 0, 1, 'L');

    // Línea 2
    $pdf->SetXY(2, 22);
    $pdf->Cell(55, 4, "TOALLITAS HUMEDAS -", 0, 1, 'L');

    // Línea 3 - CON
    $pdf->SetXY(2, 26);
    $pdf->Cell(8, 4, "{$dimensiones} CON ", 0, 0, 'L');

     $pdf->SetXY(28, 26);
    // Número de unidades - MÁS GRANDE
    $pdf->SetFont('helvetica', 'B', 13);
    $pdf->Cell(15, 4, $unidades, 0, 0, 'C');

    // Resto de la línea 3
     $pdf->SetXY(39, 26);
    $pdf->SetFont('helvetica', 'B',11);
    $pdf->Cell(25, 4, ' UNIDADES', 0, 1, 'L');

    // Descripción en portugués
    $pdf->SetFont('helvetica', 'B', 9);
    $pdf->SetXY(2, 38);
    $pdf->MultiCell(55, 3, "COMTEM {$paquetes} PACOTES DE LENÇO\nHUMEDECIDO - {$dimensiones} - DE\n{$unidades} FOLHAS", 0, 'L');

    // Sección derecha - Información de fabricación
    $pdf->SetLineWidth(0.5);

    // Recuadro superior derecho - Fecha y Lote
    $pdf->Rect(65, 5, 32, 18, 'D');
    $pdf->SetFont('helvetica', 'B', 6);
    $pdf->SetXY(67, 7);
    $pdf->Cell(28, 2.5, 'FECHA DE FABRICACION', 0, 1, 'L');
    $pdf->SetFont('helvetica', 'B', 8);
    $pdf->SetXY(67, 11);
    $pdf->Cell(28, 3, date('d/m/Y'), 0, 1, 'L');
    $pdf->SetFont('helvetica', 'B', 7);
    $pdf->SetXY(67, 16);
    $pdf->Cell(28, 2.5, 'LOTE:' . str_pad($orden['id'], 5, '0', STR_PAD_LEFT), 0, 1, 'L');

    // Recuadro medio derecho - Información de la empresa
    $pdf->SetLineWidth(0.5);

    $pdf->Rect(65, 23, 32, 20, 'D'); // Reducido de 25 a 20 para dar espacio a los códigos de barras
    $pdf->SetFont('helvetica', 'B', 5);
    $pdf->SetXY(67, 26);
    $pdf->Cell(28, 2, 'FABRICADO POR', 0, 1, 'L');
    $pdf->SetXY(67, 28);
    $pdf->Cell(28, 2, 'AMERICA TNT S.A.', 0, 1, 'L');
    $pdf->SetXY(67, 30);
    $pdf->Cell(28, 2, 'RUC: 80094986-2', 0, 1, 'L');
    $pdf->SetXY(67, 32);
    $pdf->Cell(28, 2, 'FABRICADO/EMBALADO', 0, 1, 'L');
    $pdf->SetXY(67, 34);
    $pdf->Cell(28, 2, 'EN PARAGUAY', 0, 1, 'L');

    $pdf->SetFont('helvetica', 'B', 4); // Fuente más pequeña
    $pdf->SetXY(67, 37);
    $pdf->MultiCell(28, 1.2, "SUPERCARRETERA ITAIPU\nKM 35, HERNANDARIAS,\nPARAGUAY.", 0, 'L');

    // PRIMER Código de barras Code128 (original)
    $pdf->write1DBarcode($barcode_data, 'C128', 64.5, 45, 33, 11, 0.3, array(
        'position' => 'S',
        'border' => false,
        'padding' => 1
    ));

    // Marco del primer código de barras
    $pdf->SetLineWidth(0.5);
    $pdf->Rect(65, 43, 32, 15, 'D');

    // Número del primer código de barras
    $pdf->SetFont('helvetica', 'B', 7);
    $pdf->SetXY(65, 54.7);
    $barcode_display = (strpos($barcode_data, '01') === 0) ? '(01)' . substr($barcode_data, 2) : '(01)' . $barcode_data;
    $pdf->Cell(32, 2, $barcode_display, 0, 0, 'C');

    // SEGUNDO Código de barras Code128 (nuevo - usando numero_item)
    $pdf->write1DBarcode($numero_item, 'C128', 65, 63, 32, 10, 0.3, array(
        'position' => 'S',
        'border' => false,
        'padding' => 1
    ));

    // Marco del segundo código de barras
    $pdf->SetLineWidth(0.5);
    $pdf->Rect(65, 63, 32, 12, 'D');

    // Número del segundo código de barras
    $pdf->SetFont('helvetica', 'B', 6);
    $pdf->SetXY(65, 72);
    $item_display = $numero_item_original ? 'ITEM: ' . $numero_item_original : 'ORD: ' . $id_orden;
    $pdf->Cell(32, 2, $item_display, 0, 0, 'C');

    // Sección inferior - "NAO PISE. NO PISAR" (ajustada la posición)
    $pdf->SetFont('helvetica', '', 5);
    $pdf->SetXY(2, 61); // Movido hacia arriba
    $pdf->Cell(20, 2.5, 'NAO PISE.', 0, 1, 'L');
    $pdf->SetXY(2, 63); // Movido hacia arriba
    $pdf->Cell(20, 2.5, 'NO PISAR', 0, 1, 'L');

    // Iconos (ajustada la posición)
    $icon_y = 66; // Movido hacia arriba
    $icon_size = 10;
    $icon_spacing = 12;
    $area_width = ($icon_spacing * 3) + $icon_size;
    $area_height = $icon_size;

    // Insertar imagen de iconos
    $pdf->Image('../../utils/icon.jpg', 2, $icon_y, $area_width, $area_height, 'JPG');

    // Generar el PDF
    $pdf->Output('Etiqueta_Cottonfresh_' . $id_orden . '_Item_' . ($numero_item_original ?: 'NEW') . '.pdf', 'I');
    exit();
} catch (Exception $e) {
    header("Location: ./registro_stock.php?error=" . urlencode("Error al generar la etiqueta: " . $e->getMessage()));
    exit();
}
?>