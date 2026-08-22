<?php

namespace App\Services\Sap;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Reads a SAP CDS view through the HRIS bridge at `services.sap.cds_url`.
 *
 * The bridge answers with a bare JSON array of row objects, one per record of
 * the view. `ZA_HRIS_ORGZ` is the org structure view and is around 6 MB, so
 * every call here is a deliberate, one-shot fetch — never a per-request one.
 */
class CdsClient
{
    /**
     * Fetch every row of a CDS view.
     *
     * @return array<int, array<string, string>>
     *
     * @throws RuntimeException when credentials are missing or the bridge
     *                          answers with something other than a JSON array
     */
    public function rows(string $view): array
    {
        $url = (string) config('services.sap.cds_url');
        $user = (string) config('services.sap.user');
        $pass = (string) config('services.sap.pass');

        if ($url === '' || $user === '' || $pass === '') {
            throw new RuntimeException('Kredensial SAP belum lengkap. Isi SAP_CDS_URL, SAP_USER dan SAP_PASS.');
        }

        try {
            $response = Http::timeout((int) config('services.sap.timeout', 180))
                ->retry(2, 2000, throw: false)
                ->get($url, [
                    'p_user' => $user,
                    'p_pass' => $pass,
                    'p_cds' => $view,
                ]);
        } catch (ConnectionException $e) {
            throw new RuntimeException("Tidak bisa menghubungi bridge SAP: {$e->getMessage()}", previous: $e);
        }

        if ($response->failed()) {
            throw new RuntimeException("Bridge SAP menolak permintaan {$view} (HTTP {$response->status()}).");
        }

        $rows = $response->json();

        if (! is_array($rows) || $rows === []) {
            throw new RuntimeException("Bridge SAP mengembalikan data kosong atau bukan JSON untuk {$view}.");
        }

        return $rows;
    }
}
