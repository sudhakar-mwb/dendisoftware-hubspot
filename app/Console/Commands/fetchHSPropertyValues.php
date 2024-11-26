<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use DB;
use Carbon\Carbon;
use HubSpot\Factory;
use HubSpot\Client\Crm\Properties\ApiException;
use HubSpot\Client\Crm\Properties\Model\BatchReadInputPropertyName;
use HubSpot\Client\Crm\Properties\Model\PropertyName;

class fetchHSPropertyValues extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:fetchHSPropertyValues';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $date = Carbon::now();
        $getCompaniesPropertiesData = $this->getCompanyProperties();
        if (!empty($getCompaniesPropertiesData['response']) && !empty($getCompaniesPropertiesData['response']['results'])) {
            $companiesData = [];
            foreach ($getCompaniesPropertiesData['response']['results'] as $resultKey => $resultValue) {
                if ($resultValue['name'] == "test_processed") {
                    $companiesData[$resultValue['name']] = $resultValue['options'];
                    
                    DB::table('dendisoftware_options')->updateOrInsert(
                        ['option_name' => $resultValue['name']],
                        [
                            // 'option_value' => json_encode(array_values(array_unique($contactData))),
                            'option_value' => json_encode($companiesData),
                            'updated_at'   => $date->toDateTimeString()
                        ]
                    );
                }
            }
        }
    }

     public function getCompanyProperties (){
        $curl = curl_init();

        curl_setopt_array($curl, array(
        CURLOPT_URL => 'https://api.hubapi.com/crm/v3/properties/companies/batch/read',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS =>'{
        "archived": false,
        "inputs": [
            {
            "name": "test_processed"
            }
        ]
        }',
        CURLOPT_HTTPHEADER => array(
            'content-type: application/json',
            'Authorization: Bearer '.env('HUBSPOT_ACCESS_TOKEN')
        ),
        ));

        $response = curl_exec($curl);
        curl_close($curl);
        $status_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $curl_errors = curl_error($curl);
        return ['status_code' => $status_code, 'response' => json_decode($response, true), 'errors' => $curl_errors];
    }
}
