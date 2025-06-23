<?php
if (!defined('ABSPATH')) {exit;}

class SRL_Asset_Optimizer {
    
    private static $instance = null;
    private $minified_dir = 'assets/minified/';
    private $cache_dir;

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        $this->cache_dir = SRL_PLUGIN_DIR . $this->minified_dir;
        if (!file_exists($this->cache_dir)) {
            wp_mkdir_p($this->cache_dir);
        }
    }

    public function getMinifiedAsset($file_path, $type = 'css') {
        $file_full_path = SRL_PLUGIN_DIR . $file_path;
        
        if (!file_exists($file_full_path)) {
            return $file_path;
        }
        
        $file_hash = md5_file($file_full_path);
        $file_name = pathinfo($file_path, PATHINFO_FILENAME);
        $minified_name = $file_name . '.' . $file_hash . '.min.' . $type;
        $minified_path = $this->cache_dir . $minified_name;
        $minified_url = SRL_PLUGIN_URL . $this->minified_dir . $minified_name;
        
        if (!file_exists($minified_path)) {
            $this->cleanupOldVersions($file_name, $type);
            
            $content = file_get_contents($file_full_path);
            
            if ($type === 'css') {
                $minified = $this->minifyCSS($content);
            } else {
                $minified = $this->minifyJS($content);
            }
            
            file_put_contents($minified_path, $minified);
        }
        
        return $minified_url;
    }

    private function minifyCSS($css) {
        $css = preg_replace('/\/\*.*?\*\//s', '', $css);
        $css = preg_replace('/\s+/', ' ', $css);
        $css = str_replace(array('; ', ' {', '{ ', ' }', '} ', ': ', ', '), 
                          array(';', '{', '{', '}', '}', ':', ','), $css);
        $css = trim($css);
        return $css;
    }

    private function minifyJS($js) {
        $js = preg_replace('/\/\*.*?\*\//s', '', $js);
        $js = preg_replace('/\/\/.*$/m', '', $js);
        $js = preg_replace('/\s+/', ' ', $js);
        $js = str_replace(array('; ', ' = ', ' == ', ' != ', ' && ', ' || ', ' { ', ' } '), 
                         array(';', '=', '==', '!=', '&&', '||', '{', '}'), $js);
        $js = trim($js);
        return $js;
    }

    private function cleanupOldVersions($file_name, $type) {
        $pattern = $this->cache_dir . $file_name . '.*.min.' . $type;
        $old_files = glob($pattern);
        
        foreach ($old_files as $old_file) {
            unlink($old_file);
        }
    }

    public function clearMinifiedCache() {
        $files = glob($this->cache_dir . '*.min.*');
        foreach ($files as $file) {
            unlink($file);
        }
        return count($files);
    }

    public function getOptimizedAssets() {
        $assets = array(
            'css' => array(
                'frontend-style' => $this->getMinifiedAsset('assets/css/frontend-style.css', 'css')
            ),
            'js' => array(
                'frontend-calendar' => $this->getMinifiedAsset('assets/js/frontend-calendar.js', 'js'),
                'flight-options' => $this->getMinifiedAsset('assets/js/flight-options-unified.js', 'js')
            )
        );
        
        return $assets;
    }

    public function getCombinedJS($files) {
        $combined_content = '';
        $combined_hash = '';
        
        foreach ($files as $file) {
            $file_path = SRL_PLUGIN_DIR . $file;
            if (file_exists($file_path)) {
                $combined_content .= file_get_contents($file_path) . "\n";
                $combined_hash .= filemtime($file_path);
            }
        }
        
        $hash = md5($combined_hash);
        $combined_name = 'combined.' . $hash . '.min.js';
        $combined_path = $this->cache_dir . $combined_name;
        $combined_url = SRL_PLUGIN_URL . $this->minified_dir . $combined_name;
        
        if (!file_exists($combined_path)) {
            $this->cleanupOldVersions('combined', 'js');
            $minified = $this->minifyJS($combined_content);
            file_put_contents($combined_path, $minified);
        }
        
        return $combined_url;
    }
}