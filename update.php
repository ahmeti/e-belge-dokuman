<?php

namespace Ahmeti\EBelgeDokuman;

require __DIR__.'/vendor/autoload.php';

use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Utils;
use HeadlessChromium\BrowserFactory;
use HeadlessChromium\Page;
use Symfony\Component\Filesystem\Filesystem;
use ZipArchive;

class Update
{
    private array $removeFiles = [
        'GIB_eFatura', 'GIB_UBL_eFatura', 'GIB_Kilavuz',
        'Sovos_eArsiv', 'Sovos_eFatura', 'Sovos_eIrsaliye',
        'build',
        'E_Fatura_Canli_Core_Main.js', 'E_Fatura_Canli_Ext_All.js',
        'E_Fatura_Canli_Ext_Base.js', 'Sovos_Sorulan_Sorular.xlsx',
        'Sovos_UBL_TR_Catalogue.xlsx', 'dummy-debug.html',
    ];

    private Client $client;

    private Page $page;

    public function __construct(
        private $filesystem = new Filesystem,
    ) {
        $this->client = new Client([
            'allow_redirects' => true,
            'strict' => true,
            'timeout' => 60,
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (Windows NT 6.2; WOW64; rv:17.0) Gecko/20100101 Firefox/17.0',
                'Referer' => 'https://ebelge.gib.gov.tr',
            ],
        ]);
    }

    private function clear(): void
    {
        foreach ($this->removeFiles as $removeFile) {
            $this->filesystem->remove($removeFile);
        }

        $this->filesystem->mkdir(dirname(__FILE__).'/build');
    }

    private function downloadFile(string $url, string $fileName, bool $unzip = false): void
    {
        $resource = Utils::tryFopen(dirname(__FILE__).'/'.$fileName, 'w+');
        $stream = Utils::streamFor($resource);

        $this->client->get($url, ['sink' => $stream]);

        if ($unzip) {
            $zip = new ZipArchive;
            $res = $zip->open($fileName);
            if ($res === true) {
                $zip->extractTo('.');
                $zip->close();
                unlink($fileName);
            } else {
                throw new Exception('Unzip failed!');
            }
        }
    }

    private function crawler(): void
    {
        $html = file_get_contents(dirname(__FILE__).'/dummy.html');

        $jsContent = file_get_contents(dirname(__FILE__).'/E_Fatura_Canli_Ext_Base.js');
        $jsContent .= file_get_contents(dirname(__FILE__).'/E_Fatura_Canli_Ext_All.js');
        $jsContent .= file_get_contents(dirname(__FILE__).'/E_Fatura_Canli_Core_Main.js');
        $html = str_replace('<script>//SCRIPTS_CONTENT//</script>', '<script>'.$jsContent.'</script>', $html);

        file_put_contents(dirname(__FILE__).'/dummy-debug.html', $html);

        $browserFactory = new BrowserFactory;
        $browser = $browserFactory->createBrowser();

        try {
            $this->page = $browser->createPage();
            $this->page->setHtml($html);

            $this->createWithholding();
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        } finally {
            $browser->close();
        }
    }

    private function createWithholding(): void
    {
        $withholding = [];
        $names = $this->page->evaluate('kdvTevkifatKodAciklamaListesi')->getReturnValue();
        $rates = $this->page->evaluate('kdvTevkifatKodOranListesi')->getReturnValue();

        foreach ($names as $name) {
            $code = substr($name[0], 0, 3);
            $rateKey = $name[0];
            $name = $name[1];

            if ($rateKey === '6274') {
                continue; // Aktif değil! (1/11/2022 tarihi öncesi) Demir-Çelik Ürünlerinin Teslimi [KDVGUT-(I/C-2.1.3.3.8)]
            }

            if (array_key_exists($code, $withholding)) {
                throw new Exception('Witholding duplicate code found: '.$code);
            } else {
                $withholding[$code] = [
                    'code' => $code,
                    'name' => str_replace($code.' - ', '', $name),
                    'rate' => $rates[$rateKey],
                ];
            }
        }
        $json = json_encode(array_values($withholding), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        file_put_contents(dirname(__FILE__).'/build/withholding.json', $json);
    }

    private function updateReadme(): void
    {
        date_default_timezone_set('Europe/Istanbul');

        $readme = dirname(__FILE__).'/README.md';
        $file = file($readme);
        if (! is_array($file)) {
            throw new Exception('Readme file not found!');
        }

        $file[0] = '# LAST UPDATE: '.date('Y-m-d H:i:s').PHP_EOL;
        file_put_contents($readme, $file);
    }

    public function run(): void
    {
        echo PHP_EOL.'Update started...'.PHP_EOL;

        $this->clear();

        $this->downloadFile('https://ebelge.gib.gov.tr/dosyalar/kilavuzlar/e-FaturaPaketi.zip', 'e-FaturaPaketi.zip', true);
        $this->downloadFile('https://ebelge.gib.gov.tr/dosyalar/kilavuzlar/UBL-TR1.2.1_Paketi.zip', 'UBL-TR1.2.1_Paketi.zip', true);
        $this->downloadFile('https://ebelge.gib.gov.tr/dosyalar/kilavuzlar/UBLTR_1.2.1_Kilavuzlar.zip', 'GIB_Kilavuz.zip', true);

        $this->downloadFile('https://portal.efatura.gov.tr/efatura/extjs/adapter/ext/ext-base.js', 'E_Fatura_Canli_Ext_Base.js');
        $this->downloadFile('https://portal.efatura.gov.tr/efatura/extjs/ext-all.js', 'E_Fatura_Canli_Ext_All.js');
        $this->downloadFile('https://portal.efatura.gov.tr/efatura/js/core-main.js', 'E_Fatura_Canli_Core_Main.js');

        $this->downloadFile('https://api.fitbulut.com/servis/assets/docs/Sovos%20Bulut%20e-Fatura%20WS%20API%20v2.3.zip', 'SovosFatura.zip', true);
        $this->downloadFile('https://api.fitbulut.com/servis/assets/docs/Sovos%20Bulut%20e-Ar%C5%9Fiv%20Fatura%20WS%20API%20v2.3.zip', 'SovosArsiv.zip', true);
        $this->downloadFile('https://api.fitbulut.com/servis/assets/docs/Sovos%20Bulut%20e-%C4%B0rsaliye%20WS%20API%20v1.3.zip', 'SovosIrsaliye.zip', true);

        $this->downloadFile('https://api.fitbulut.com/servis/assets/docs/Sovos%20R&D%20-%20UBL-TR%20Catalogue.xlsx', 'Sovos_UBL_TR_Catalogue.xlsx');
        $this->downloadFile('https://api.fitbulut.com/servis/assets/docs/Sovos%20R&D%20-S%C4%B1k%20Sorulan%20Sorular.xlsx', 'Sovos_Sorulan_Sorular.xlsx');

        $this->filesystem->rename(dirname(__FILE__).'/e-FaturaPaketi', 'GIB_eFatura');
        $this->filesystem->rename(dirname(__FILE__).'/UBLTR_1.2.1_Paketi', 'GIB_UBL_eFatura');
        $this->filesystem->rename(dirname(__FILE__).'/Sovos Bulut e-Arşiv Fatura WS API 2.3', 'Sovos_eArsiv');
        $this->filesystem->rename(dirname(__FILE__).'/Sovos Bulut e-Fatura WS API v2.3', 'Sovos_eFatura');
        $this->filesystem->rename(dirname(__FILE__).'/Sovos Bulut e-İrsaliye WS API v1.3', 'Sovos_eIrsaliye');

        $this->filesystem->rename(dirname(__FILE__).'/UBLTR_1.2.1_Kilavuzlar', 'GIB_Kilavuz');
        $this->filesystem->rename(dirname(__FILE__).'/GIB_Kilavuz/UBLTR_1.2.1_K_ılavuzlar/GENEL AÇIKLAMALAR', dirname(__FILE__).'/GIB_Kilavuz/GENEL AÇIKLAMALAR');
        $this->filesystem->rename(dirname(__FILE__).'/GIB_Kilavuz/UBLTR_1.2.1_K_ılavuzlar/KOD LİSTELERİ', dirname(__FILE__).'/GIB_Kilavuz/KOD LİSTELERİ');
        $this->filesystem->rename(dirname(__FILE__).'/GIB_Kilavuz/UBLTR_1.2.1_K_ìlavuzlar/BELGELER', dirname(__FILE__).'/GIB_Kilavuz/BELGELER');
        $this->filesystem->rename(dirname(__FILE__).'/GIB_Kilavuz/UBLTR_1.2.1_K_ìlavuzlar/ORTAK ELEMANLAR', dirname(__FILE__).'/GIB_Kilavuz/ORTAK ELEMANLAR');
        $this->filesystem->rename(dirname(__FILE__).'/GIB_Kilavuz/UBLTR_1.2.1_K_ìlavuzlar/SENARYOLAR', dirname(__FILE__).'/GIB_Kilavuz/SENARYOLAR');

        $this->filesystem->remove(dirname(__FILE__).'/GIB_Kilavuz/UBLTR_1.2.1_K_ılavuzlar');
        $this->filesystem->remove(dirname(__FILE__).'/GIB_Kilavuz/UBLTR_1.2.1_K_ìlavuzlar');

        $this->crawler();

        $this->updateReadme();

        echo 'Update finished...'.PHP_EOL;
    }
}

(new Update)->run();
