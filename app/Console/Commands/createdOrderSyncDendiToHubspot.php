<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use DB;
use Carbon\Carbon;
use DateTime;
use App\Traits\DendiApis;
use HubSpot\Factory;
use HubSpot\Client\Crm\Contacts\ApiException;
use HubSpot\Client\Crm\Contacts\Model\AssociationSpec;
use HubSpot\Client\Crm\Contacts\Model\PublicAssociationsForObject;
use HubSpot\Client\Crm\Contacts\Model\PublicObjectId;
use HubSpot\Client\Crm\Contacts\Model\SimplePublicObjectInputForCreate;

class createdOrderSyncDendiToHubspot extends Command
{
    use DendiApis;
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'get:createdOrderSyncDendiToHubspot';

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
        $client = Factory::createWithAccessToken(env('HUBSPOT_ACCESS_TOKEN'));
        $date   = Carbon::now();
        $dbData = DB::table('dendisoftware_options')->select('option_value')->where('option_name', 'created_orderIds')->first();
        $count  = 0;

        if (empty($dbData)) {
            \Log::info("Index not found");
            return Command::SUCCESS;
        } else {
            $dbData = json_decode($dbData->option_value);
        }
        if (empty($dbData)) {
            \Log::info("No contact found to sync");
            return Command::SUCCESS;
        } else {
            $contactIds = $dbData;
        }

        foreach ($contactIds as $key => $contactId) {

            $hsContactId = $contactId;
            $count++;
            $contactProperties = array();
            if ($count > 5) {
                \Log::info("5 contacts synced");
                break;
            }
            \Log::info('Order Id. '. $contactId);
            // fetch order from Dendi
            $dendiOrderResponse = $this->_getDendiData('api/v1/orders/'.$contactId);

            $sample = [];
            $Test_Processed = [] ;
            foreach ($dendiOrderResponse['response']['test_panels'] as $key1 => $value1) {
                $Test_Processed[] = !empty( $value1['test_panel_type']['name']) ?  $value1['test_panel_type']['name'] : "" ;
                foreach ($value1['samples'] as $key2 => $value2) {
                    $sample[] = $value2;
                }
            }

            $first_sample_date = $sample[0]["collection_date"];
            $last_sample_date  = $sample[count($sample) - 1]["collection_date"];

            $firstSample = $this->getMonthAndYear($first_sample_date);
            $lastSample  = $this->getMonthAndYear($last_sample_date);

            // Check if the year is the same
            if ($firstSample === $lastSample) {
                $result = $firstSample;
            } else {
                // Extract the month parts and combine
                $firstMonth = (new DateTime($first_sample_date))->format('F');
                $lastMonth  = (new DateTime($last_sample_date))->format('F');
                $year       = (new DateTime($first_sample_date))->format('Y');
                // Combine the month and year into the desired format
                $result = "$firstMonth - $lastMonth $year";
            }

            if ( !empty($dendiOrderResponse) && !empty($dendiOrderResponse['response']) && !empty($dendiOrderResponse['response']['uuid']) ) {
                if (!empty($dendiOrderResponse['response']['provider'])) {
                    $company = !empty($dendiOrderResponse['response']['provider']['user']['first_name'] || $dendiOrderResponse['response']['provider']['user']['last_name']) ? $dendiOrderResponse['response']['provider']['user']['first_name']. ' ' .$dendiOrderResponse['response']['provider']['user']['last_name']: "";
                }
                $mapedData = [
                    'firstname' => $dendiOrderResponse['response']['patient']['user']['first_name'],
                    'lastname'  => $dendiOrderResponse['response']['patient']['user']['last_name'],
                    'email'     => $dendiOrderResponse['response']['patient']['user']['email'] ?? "",
                    'company'   => !empty($company) ? $company : '',
                    'phone'     => $dendiOrderResponse['response']['patient']['phone_number'] ?? "",
                    // 'specialty' => '', // need to property data confirmation
                    // 'account_status'        => $dendiOrderResponse['response']['status'], // order status
                    'first_sample_received' => explode(' ', $first_sample_date)[0] ?? '',
                    'last_sample_received'  => explode(' ', $last_sample_date)[0] ?? '',
                    // 'sample_count_volume'   => $result,
                    'test_processed'         => !empty($Test_Processed) ? implode(', ', $Test_Processed): "",
                    'number_of_samples_sent' => count($sample), // number of total sample
                    'address'               => $dendiOrderResponse['response']['patient']['address1'] . ' '. $dendiOrderResponse['response']['patient']['address2'],
                    'patient_uuid'=>  !empty($dendiOrderResponse['response']['patient']['uuid']) ? $dendiOrderResponse['response']['patient']['uuid'] : '',
                ];

                \Log::info('Patient contact hs property data. ');
                \Log::info($mapedData);

                $simplePublicObjectInputForCreate = new SimplePublicObjectInputForCreate([
                    'associations' => null,
                    'properties'   => $mapedData,
                ]);
                try {
                    $apiResponse = $client->crm()->contacts()->basicApi()->create($simplePublicObjectInputForCreate);
                    $apiResponse = json_decode($apiResponse);
                    if ($apiResponse->id && !empty($apiResponse->properties)) {
                        \Log::info('Patient contact created successfully.');
                    } else {
                        \Log::info('Error during patient contact create in hubspot. ');
                        \Log::info($apiResponse);
                    }
                } catch (ApiException $e) {
                    echo "Exception when calling basic_api->create: ", $e->getMessage();
                }
                
            } else {
                \Log::info("HubSpot contact data not fetched using hsContactID.");
                \Log::info($contactId);
            }

            \Log::info("HubSpot to dendi data map now unset id.");
            \Log::info($contactIds[$key]);

            unset($contactIds[$key]);
        }
        DB::table('dendisoftware_options')->updateOrInsert(
            ['option_name' => 'created_orderIds'],
            [
                'option_value' => json_encode(array_values(array_unique($contactIds))),
                'updated_at' => $date->toDateTimeString()
            ]
        );
    
    }

    public function getMonthAndYear($dateString) {
        $date  = new DateTime($dateString);
        $month = $date->format('F'); // Full month name (e.g., January)
        $year  = $date->format('Y');  // Full year (e.g., 2024)
        return "$month $year";
    }
}
