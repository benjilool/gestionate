<?php
/** Formato de números y unidades, compartido por inventario, ingredientes y platos. */

/** Muestra 3.00 como "3" y 2.50 como "2,5" (formato chileno, sin ceros de más). */
function formatoCantidad(string|float $numero): string
{
    return rtrim(rtrim(number_format((float) $numero, 2, ',', '.'), '0'), ',');
}

/** Muestra 4700 como "$4.700". */
function formatoPesos(int|string $monto): string
{
    return '$' . number_format((int) $monto, 0, ',', '.');
}

/** Abreviatura de la unidad para las tablas. */
function unidadCorta(string $unidad): string
{
    return match ($unidad) {
        'unidad' => 'u.',
        'litro'  => 'L',
        default  => $unidad,
    };
}

/**
 * Convierte el texto de un formulario en decimal de 2 cifras.
 * En Chile se escribe "2,5": se acepta la coma. Devuelve null si no es un número.
 */
function leerDecimal(string $texto): ?float
{
    $numero = filter_var(str_replace(',', '.', trim($texto)), FILTER_VALIDATE_FLOAT);
    return $numero === false ? null : round($numero, 2);   // las columnas guardan 2 decimales
}
