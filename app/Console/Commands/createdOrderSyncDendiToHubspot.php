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

use HubSpot\Client\Crm\Companies\ApiException as CompaniesApiException;
use HubSpot\Client\Crm\Companies\Model\SimplePublicObjectInput;
use HubSpot\Client\Crm\Associations\Model\PublicAssociation;
use HubSpot\Client\Crm\Associations\V4\ApiException as AssociationsApiException;
use HubSpot\Client\Crm\Associations\V4\Model\AssociationSpec as AssociationsAssociationSpec;
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
            \Log::info('Created Order Id. '. $contactId);
            // fetch order from Dendi
            $dendiOrderResponse = $this->_getDendiData('api/v1/orders/'.$contactId);

            if (!empty($dendiOrderResponse['response']['provider']['uuid'])) {
                $dendiProviderResponse = $this->_getDendiData('api/v1/providers/'.$dendiOrderResponse['response']['provider']['uuid']);
            }

            if (!empty($dendiOrderResponse['response']['account']['uuid'])) {
                $dendiAccountResponse = $this->_getDendiData('api/v1/accounts/'.$dendiOrderResponse['response']['account']['uuid']);
            }

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
                // $result = "$firstMonth - $lastMonth $year";
            }



            $matchedLabels = [];
            $DBTestProcessed = DB::table('dendisoftware_options')->where(['option_name'=>'test_processed'])->first();
            if ($DBTestProcessed->option_value) {
                $DBTestProcessedValue = json_decode($DBTestProcessed->option_value, true);
                foreach ($DBTestProcessedValue['test_processed'] as $item) {
                    if (in_array($item['label'], $Test_Processed)) {
                        $matchedLabels[] = $item['label'];
                    }
                }
            }
            $uniqueLabels = implode(', ', array_unique($matchedLabels));

            if ( !empty($dendiOrderResponse) && !empty($dendiOrderResponse['response']) && !empty($dendiOrderResponse['response']['uuid']) ) {

                $mapedData = [
                    'firstname' => $dendiOrderResponse['response']['provider']['user']['first_name'] ?? "",
                    'lastname'  => $dendiOrderResponse['response']['provider']['user']['last_name']  ?? "",
                    'email'     => $dendiOrderResponse['response']['provider']['user']['email']      ?? "",
                    'company'   => $dendiOrderResponse['response']['account']['name']                ?? "",
                    'phone'     => $dendiOrderResponse['response']['patient']['phone_number']        ?? "",
                    // 'specialty' => '', // need to property data confirmation
                    // 'account_status'        => $dendiOrderResponse['response']['status'], // order status
                    'first_sample_received' => explode(' ', $first_sample_date)[0] ?? '',
                    'last_sample_received'  => explode(' ', $last_sample_date)[0] ?? '',
                    // 'sample_count_volume'   => $result,
                    'test_processed'         => !empty($Test_Processed) ? implode(', ', $Test_Processed): "",
                    'number_of_samples_sent' => count($sample), // number of total sample
                    'address'               => $dendiOrderResponse['response']['patient']['address1'] . ' '. $dendiOrderResponse['response']['patient']['address2'],
                    // 'patient_uuid'=>  !empty($dendiOrderResponse['response']['patient']['uuid']) ? $dendiOrderResponse['response']['patient']['uuid'] : '',
                    // 'dendi_order_id' => !empty($dendiOrderResponse['response']['code']) ? $dendiOrderResponse['response']['code'] : '',
                    "provider_uuid"  => $dendiOrderResponse['response']['provider']['uuid'],
                    "npi__"          => $dendiOrderResponse['response']['provider']['npi'],   // for US Only
                    // "provider_id"    => $dendiOrderResponse['response']['provider']['uuid'],  // for international
                ];

                $companyMapData = [
                    "name"         => $dendiOrderResponse['response']['account']['name'] ?? "",
                    "account_uuid" => $dendiOrderResponse['response']['account']['uuid'] ?? "",
                    "total_number_of_tests" =>  count($sample) ?? "",
                    "test_processed"        =>  !empty($uniqueLabels) ? $uniqueLabels : "",
                ];



                // company associated

                \Log::info('Provider as contact mapping data. ');
                \Log::info($mapedData);

                \Log::info('Account as company mapping data. ');
                \Log::info($companyMapData);

                // Provider As Contact (NPI Id Must be exactly 10 digits and unique)
                // Account  As Company (Account name unique)
                sleep(15);
                $response        = $this->searchsContact( $dendiOrderResponse['response']['provider']['uuid'] );
                $companyResponse = $this->hubspotSearchCompany('account_uuid', $dendiOrderResponse['response']['account']['uuid'], ['name']);
                $companyResponse = json_decode($companyResponse);

                if ($response['status'] == false && $response['message'] == "contact alredy exist.") {
                        // Need to contact update
                        unset($mapedData['first_sample_received']);
                        $simplePublicObjectInputForCreate = new SimplePublicObjectInputForCreate([
                            'associations' => null,
                            'properties'   => $mapedData,
                        ]);
                        try {
                            $updateResponse = $client->crm()->contacts()->basicApi()->update($response['contactId'],$simplePublicObjectInputForCreate);
                            $updateResponse = json_decode($updateResponse);
                            if ($updateResponse->id && !empty($updateResponse->properties)) {

                                if ($companyResponse->total > 0) {
                                    // if company exists then update the company property and associated with contact

                                    $companyId    = $companyResponse->results[0]->id;
                                    $companyInput = new SimplePublicObjectInput([
                                        'properties' => $companyMapData
                                    ]);
                                    $newCompanyResponse = $client->crm()->companies()->basicApi()->update($companyId,$companyInput);
                                    $newCompanyId       = json_decode($newCompanyResponse);

                                    if (!empty($newCompanyId->id)) {
                                        $associationSpec2 = new AssociationsAssociationSpec([
                                            'association_category' => 'HUBSPOT_DEFINED', 
                                            'association_type_id'  => 279,  
                                        ]);
                                    
                                        $associationResponse = $client->crm()->associations()->v4()->basicApi()->create('contacts', $updateResponse->id, 'companies', $newCompanyId->id, [$associationSpec2]);
                                        $associationResponse = json_decode($associationResponse);
                                        $associationId       = $associationResponse->toObjectTypeId;
                                        if (empty($associationId)) {
                                            \Log::info("Error during hubspot contacts updation and companies associations ");
                                            \Log::info($associationResponse);
                                        }
                                    }else{
                                        \Log::info("Error during hubspot companies creation ");
                                        \Log::info($newCompanyResponse);
                                    }
                                    

                                }else{
                                    // create the company property and associated with the contact

                                    $companyInput = new SimplePublicObjectInput([
                                        'properties' => $companyMapData
                                    ]);
                                    $newCompanyResponse = $client->crm()->companies()->basicApi()->create($companyInput);
                                    $newCompanyId       = json_decode($newCompanyResponse);

                                    if (!empty($newCompanyId->id)) {
                                        $associationSpec2 = new AssociationsAssociationSpec([
                                            'association_category' => 'HUBSPOT_DEFINED', 
                                            'association_type_id'  => 279,  
                                        ]);
                                    
                                        $associationResponse = $client->crm()->associations()->v4()->basicApi()->create('contacts', $updateResponse->id, 'companies', $newCompanyId->id, [$associationSpec2]);
                                        $associationResponse = json_decode($associationResponse);
                                        $associationId       = $associationResponse->toObjectTypeId;
                                        if (empty($associationId)) {
                                            \Log::info("Error during hubspot contacts updation and companies associations ");
                                            \Log::info($associationResponse);
                                        }
                                    }else{
                                        \Log::info("Error during hubspot companies creation ");
                                        \Log::info($newCompanyResponse);
                                    }

                                }
                                
                            } else {
                                \Log::info('Error during patient contact updation in hubspot. ');
                                \Log::info($updateResponse);
                            }
                        } catch (ApiException $e) {
                            echo "Exception when calling basic_api->create: ", $e->getMessage();
                        }
                }elseif($response['status'] == true && $response['message'] == "contact not exist."){
                        // Need to contact create
                        $simplePublicObjectInputForCreate = new SimplePublicObjectInputForCreate([
                            'associations' => null,
                            'properties'   => $mapedData,
                        ]);
                        try {
                            $apiResponse = $client->crm()->contacts()->basicApi()->create($simplePublicObjectInputForCreate);
                            $apiResponse = json_decode($apiResponse);
                            if ($apiResponse->id && !empty($apiResponse->properties)) {

                                if ($companyResponse->total > 0) {
                                    // if company exists then update the company property and associated with contact

                                    $companyId    = $companyResponse->results[0]->id;
                                    $companyInput = new SimplePublicObjectInput([
                                        'properties' => $companyMapData
                                    ]);
                                    $newCompanyResponse = $client->crm()->companies()->basicApi()->update($companyId,$companyInput);
                                    $newCompanyId       = json_decode($newCompanyResponse);

                                    if (!empty($newCompanyId->id)) {
                                        $associationSpec2 = new AssociationsAssociationSpec([
                                            'association_category' => 'HUBSPOT_DEFINED', 
                                            'association_type_id'  => 279,  
                                        ]);
                                    
                                        $associationResponse = $client->crm()->associations()->v4()->basicApi()->create('contacts', $apiResponse->id, 'companies', $newCompanyId->id, [$associationSpec2]);
                                        $associationResponse = json_decode($associationResponse);
                                        $associationId = $associationResponse->toObjectTypeId;
                                        if (empty($associationId)) {
                                            \Log::info("Error during hubspot contacts updation and companies associations ");
                                            \Log::info($associationResponse);
                                        }
                                    }else{
                                        \Log::info("Error during hubspot companies creation ");
                                        \Log::info($newCompanyResponse);
                                    }

                                }else{
                                    // create the company property and associated with the contact

                                    $companyInput = new SimplePublicObjectInput([
                                        'properties' => $companyMapData
                                    ]);
                                    $newCompanyResponse = $client->crm()->companies()->basicApi()->create($companyInput);
                                    $newCompanyId       = json_decode($newCompanyResponse);

                                    if (!empty($newCompanyId->id)) {
                                        $associationSpec2 = new AssociationsAssociationSpec([
                                            'association_category' => 'HUBSPOT_DEFINED', 
                                            'association_type_id'  => 279,  
                                        ]);
                                    
                                        $associationResponse = $client->crm()->associations()->v4()->basicApi()->create('contacts', $apiResponse->id, 'companies', $newCompanyId->id, [$associationSpec2]);
                                        $associationResponse = json_decode($associationResponse);
                                        $associationId       = $associationResponse->toObjectTypeId;
                                        if (empty($associationId)) {
                                            \Log::info("Error during hubspot contacts updation and companies associations ");
                                            \Log::info($associationResponse);
                                        }
                                    }else{
                                        \Log::info("Error during hubspot companies creation ");
                                        \Log::info($newCompanyResponse);
                                    }

                                }
                                
                            } else {
                                \Log::info('Error during patient contact create in hubspot. ');
                                \Log::info($apiResponse);
                            }
                        } catch (ApiException $e) {
                            echo "Exception when calling basic_api->create: ", $e->getMessage();
                        }
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

    public function searchsContact ( $data ){

        $response = ["status" => true, "message" => "contact not exist."];
        if (!empty($data)) {
            $hsContactRecord = $this->hubspotSearchContact('provider_uuid', $data, ['firstname', 'lastname', 'company', 'npi__', 'email', 'state','account_uuid']);
            $hsContactRecord = json_decode($hsContactRecord[0]);

            if ( !empty($hsContactRecord->total) && ( $hsContactRecord->total > 0 )) {
                $response = ["status" => false, "message" => "contact alredy exist.", "contactId" => $hsContactRecord->results[0]->id];
            }
        }
        return $response;
    }

    public function hubspotSearchCompany($searchBy, $searchValue, $properties = array())
    {

        $hubspot = \HubSpot\Factory::createWithAccessToken(env('HUBSPOT_ACCESS_TOKEN'));

        if (empty($searchBy) && empty($searchValue)) {
            return '';
        }

        $filter = new \HubSpot\Client\Crm\Companies\Model\Filter();
        $filter
            ->setOperator('EQ')
            ->setPropertyName($searchBy)
            ->setValue($searchValue);
        $filterGroup = new \HubSpot\Client\Crm\Companies\Model\FilterGroup();
        $filterGroup->setFilters([$filter]);

        $searchRequest = new \HubSpot\Client\Crm\Companies\Model\PublicObjectSearchRequest();
        $searchRequest->setFilterGroups([$filterGroup]);

        if (count($properties) !== 0) {
            // Get specific properties
            $searchRequest->setProperties($properties);
        }
        \Log::info("hubspotSearch Companies " . $searchRequest);
        $companies = $hubspot->crm()->Companies()->searchApi()->doSearch($searchRequest);

        // Rate Limit
        if ( $companies[1] == 429) {
            sleep(pow(2, 5));
            $companies = $hubspot->crm()->Companies()->searchApi()->doSearchWithHttpInfo($searchRequest);
        }
        return $companies;
    }
}
