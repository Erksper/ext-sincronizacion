<?php

namespace Espo\Modules\Sincronizacion\Utils;

/**
 * Utilidades para formateo de strings
 */
class StringUtils
{
    /**
     * Convierte texto a minúsculas
     * Usado para campos como email, username, telMovil, puesto, etc.
     */
    public static function toLowerCase(?string $text): ?string
    {
        if ($text === null || trim($text) === '') {
            return null;
        }
        
        return mb_strtolower(trim($text), 'UTF-8');
    }
    
    /**
     * Capitaliza la primera letra de cada palabra
     * Usado para nombres y apellidos
     * 
     * Ejemplos:
     * - "juan carlos" -> "Juan Carlos"
     * - "DE LA CRUZ" -> "De La Cruz"
     * - "maría josé" -> "María José"
     */
    public static function capitalizeWords(?string $text): ?string
    {
        if ($text === null || trim($text) === '') {
            return null;
        }
        
        $text = trim($text);
        
        // Convertir todo a minúsculas primero
        $text = mb_strtolower($text, 'UTF-8');
        
        // Capitalizar primera letra de cada palabra
        $words = preg_split('/\s+/u', $text);
        $capitalizedWords = array_map(function($word) {
            if (empty($word)) {
                return $word;
            }
            return mb_strtoupper(mb_substr($word, 0, 1, 'UTF-8'), 'UTF-8') . 
                   mb_substr($word, 1, null, 'UTF-8');
        }, $words);
        
        return implode(' ', $capitalizedWords);
    }
    
    /**
     * Combina apellido paterno y materno con formato correcto
     * 
     * @param string|null $apellidoP Apellido paterno
     * @param string|null $apellidoM Apellido materno
     * @return string|null Apellidos combinados y capitalizados
     * 
     * Ejemplos:
     * - ("García", "López") -> "García López"
     * - ("PÉREZ", null) -> "Pérez"
     * - (null, "MARTÍNEZ") -> "Martínez"
     */
    public static function combineApellidos(?string $apellidoP, ?string $apellidoM): ?string
    {
        $apellidos = [];
        
        if (!empty($apellidoP)) {
            $apellidos[] = self::capitalizeWords($apellidoP);
        }
        
        if (!empty($apellidoM)) {
            $apellidos[] = self::capitalizeWords($apellidoM);
        }
        
        if (empty($apellidos)) {
            return null;
        }
        
        return implode(' ', $apellidos);
    }
    
    /**
     * Normaliza un string para comparación
     * Elimina espacios extras, convierte a minúsculas
     */
    public static function normalize(?string $text): string
    {
        if ($text === null) {
            return '';
        }
        
        // Eliminar espacios múltiples y convertir a minúsculas
        $text = preg_replace('/\s+/', ' ', trim($text));
        return mb_strtolower($text, 'UTF-8');
    }
}