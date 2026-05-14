<?php
require_once __DIR__ . '/logging.php';
require_once __DIR__ . '/requestfunc.php';
require_once __DIR__ . '/settings.php';

class CurrencyConverter
{
    private const FRANKFURTER_CURRENCIES = [
        "AUD","BGN","BRL","CAD","CHF","CNY","CZK","DKK","EUR","GBP","HKD","HUF","IDR",
        "ILS","INR","ISK","JPY","KRW","MXN","MYR","NOK","NZD","PHP","PLN","RON","SEK",
        "SGD", "THB","TRY","USD","ZAR" 
    ];
    
    private const TURKISH_BANK_CURRENCIES = ['RUB','PKR','QAR','KRW','AZN','AED'];
    private static function getCacheDir(): string {
        return __DIR__ . '/' . get_cache_path('currencyCache');
    }
    
    public static function convert(float $amount, string $from): float
    {
        if (empty($from) || $from === 'USD') return $amount;
        
        try {
            if (in_array($from, self::FRANKFURTER_CURRENCIES)) {
                return self::convertFromFrankfurter($amount, $from);
            }

            if (in_array($from, self::TURKISH_BANK_CURRENCIES)) {
                return self::convertFromTurkishBank($amount, $from);
            } 
            
            add_error_log("Currency $from is not supported by any conversion APIs!");
            return $amount;
        } catch (Exception $e) {
            add_error_log("Currency conversion failed for $amount $from to USD: " . $e->getMessage());
            return $amount;
        }
    }
    
    private static function convertFromFrankfurter(float $amount, string $from): float
    {
        $url = "https://api.frankfurter.dev/v1/latest?base=$from&symbols=USD";
        
        $cacheDir = self::getCacheDir();
        if (!file_exists($cacheDir)) 
            mkdir($cacheDir, 0755, true);
        $cacheFile = $cacheDir . "/$from.json";
        
        if (file_exists($cacheFile) && filemtime($cacheFile) > (time() - 600)) {
            $res = json_decode(file_get_contents($cacheFile), true);
        } else {
            $curlRes = get($url);
            if ($curlRes['error']) {
                add_error_log("Curl error while trying to get Frankfurter rates: " . $curlRes['error']);
                return $amount;
            }
            file_put_contents($cacheFile, $curlRes['content']);
            $res = json_decode($curlRes['content'], true);
        }
        
        $rate = $res['rates']['USD'];
        if (empty($rate)) {
            add_error_log("Currency conversion failed for $from to USD! Rate is empty! Url: $url");
            return $amount;
        }
        return round($amount * $rate, 2);
    }
    
    private static function convertFromTurkishBank(float $amount, string $from): float
    {
        // Get the XML file from Turkish Central Bank
        $xmlUrl = 'https://www.tcmb.gov.tr/kurlar/today.xml';
        
        $cacheDir = self::getCacheDir();
        if (!file_exists($cacheDir)) 
            mkdir($cacheDir, 0755, true);
        $cacheFile = $cacheDir . '/tur.xml';
        $useCache = false;
        
        if (file_exists($cacheFile)) {
            $fileTime = filemtime($cacheFile);
            $currentTime = time();
            // Use cached file if it's less than 6 hours old
            if (($currentTime - $fileTime) < 21600) {
                $useCache = true;
                $xmlContent = file_get_contents($cacheFile);
            }
        }
        
        if (!$useCache) {
            $curlRes = get($xmlUrl);
            if ($curlRes['error']) {
                add_error_log("Curl error while trying to get Turkish Central Bank rates: " . $curlRes['error']);
                return $amount;
            }
            
            $xmlContent = $curlRes['content'];
            // Cache the XML content
            file_put_contents($cacheFile, $xmlContent);
        }
        
        // Parse the XML
        $xml = simplexml_load_string($xmlContent);
        if ($xml === false) {
            add_error_log("Failed to parse Turkish Central Bank XML data");
            return $amount;
        }
        
        // Find the currency in the XML
        $rate = null;
        foreach ($xml->Currency as $currency) {
            $currencyCode = (string)$currency['CurrencyCode'];
            if ($currencyCode === $from) {
                $rate = (float)$currency->CrossRateUSD;
                break;
            }
        }
        
        if ($rate === null || $rate <= 0) {
            add_error_log("Currency $from not found in Turkish Central Bank data or invalid rate");
            return $amount;
        }
        
        // Calculate USD amount (divide by rate for currencies where 1 USD = X currency)
        return round($amount / $rate, 2);
    }
}
