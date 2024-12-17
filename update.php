<?php

class Update
{
    public function downloadFile(string $zipUrl, string $fileName, bool $unzip = false): void
    {
        $file = fopen (dirname(__FILE__) . '/'.$fileName, 'w+');

        $ch = curl_init($zipUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_FILE, $file);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_exec($ch);
        curl_close($ch);
        fclose($file);


        if($unzip){
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

    public function run(): void
    {
        echo PHP_EOL;
        echo 'Update started...'.PHP_EOL;
        $this->downloadFile('https://ebelge.gib.gov.tr/dosyalar/kilavuzlar/e-FaturaPaketi.zip', 'e-FaturaPaketi.zip');
        $this->downloadFile('https://api.fitbulut.com/servis/assets/docs/Sovos%20Bulut%20e-Fatura%20WS%20API%20v2.2.zip', 'SovosFatura.zip', true);
        $this->downloadFile('https://api.fitbulut.com/servis/assets/docs/Sovos%20Bulut%20e-Ar%C5%9Fiv%20Fatura%20WS%20API%202.2.zip', 'SovosArsiv.zip', true);
        $this->downloadFile('https://api.fitbulut.com/servis/assets/docs/Sovos%20Bulut%20e-%C4%B0rsaliye%20WS%20API.zip', 'SovosIrsaliye.zip', true);
        echo 'Update finished...'.PHP_EOL;
    }
}

(new Update)->run();
