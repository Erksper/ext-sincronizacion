<?php
namespace Espo\Modules\Sincronizacion\Utils;

/**
 * Utilidades para formateo de strings
 */
class StringUtils
{
    /**
     * Convierte texto a minúsculas
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
     */
    public static function capitalizeWords(?string $text): ?string
    {
        if ($text === null || trim($text) === '') {
            return null;
        }
        $text = trim($text);
        $text = mb_strtolower($text, 'UTF-8');
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
     * Combina apellido paterno y materno
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
     * Normaliza un string para comparación (elimina espacios extras, convierte a minúsculas)
     */
    public static function normalize(?string $text): string
    {
        if ($text === null) {
            return '';
        }
        $text = preg_replace('/\s+/', ' ', trim($text));
        return mb_strtolower($text, 'UTF-8');
    }
    
    /**
     * Normaliza una dirección para comparación: elimina signos de puntuación, espacios extras y convierte a minúsculas.
     * Conserva letras (incluyendo acentos) y números.
     * 
     * @param string|null $text
     * @return string
     */
    public static function normalizeAddress(?string $text): string
    {
        if ($text === null) {
            return '';
        }
        // Eliminar caracteres que no sean letras (con acentos), números y espacios
        $text = preg_replace('/[^\p{L}\p{N}\s]/u', '', $text);
        // Colapsar espacios múltiples
        $text = preg_replace('/\s+/', ' ', $text);
        // Trim y minúsculas
        return mb_strtolower(trim($text), 'UTF-8');
    }
}