<?php

namespace App\Console\Commands;

use App\Models\DeviceVariant;

use League\Csv\Reader;

use Illuminate\Console\Command;

use Illuminate\Support\Str;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

use Exception;

class RefreshDeviceVariants extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'refresh:device-variants';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Refreshes variants of certified Android devices from Google Play Store\'s public listing to our internal database.';

    const DEVICES_LIST_URL = 'https://storage.googleapis.com/play_public/supported_devices.csv';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $log = Log::channel('device-variants-updater');

        $response = Http::get( self::DEVICES_LIST_URL );

        if (!$response->successful()) {
            $log->critical("couldn't update device variants list: {$response->body()}");

            return Command::FAILURE;
        }

        // For some reason Google includes this character all over the file (character zero/EOF), so we're just gonna remove it.
        $rawCsv = Str::replace(' ', '', $response->body());

        $csv = Reader::createFromString( $rawCsv );
        $csv->setHeaderOffset(0);

        $records = $csv->getRecords();

        $recordCount = $csv->count() - 1;

        foreach ($records as $index => $record) {
            foreach ($record as $key => $value) {
                $record[ $key ] = mb_convert_encoding($value, 'UTF-8');
            }

            try {
                $updated =
                    DB::collection( (new DeviceVariant())->getTable() )
                    ->where('model', $record['Model'])
                    ->update([
                        'brand'      => $record['Retail Branding'],
                        'publicName' => $record['Marketing Name'],
                        'codename'   => $record['Device'],
                        'model'      => $record['Model']
                    ], [ 'upsert' => true ]);
            } catch (Exception $exception) {
                $log->error(
                    "{$index} / {$recordCount} - {$record['Model']}: failed to insert/update variant information: {$exception->getMessage()}" . PHP_EOL .
                    PHP_EOL .
                    $exception->getTraceAsString()
                );

                continue;
            }

            if ($updated) {
                $log->debug("{$index} / {$recordCount} - {$record['Model']}: success inserting/updating data!");
            } else {
                $log->debug("{$index} / {$recordCount} - {$record['Model']}: nothing to do.");
            }
        }

        return Command::SUCCESS;
    }

}
