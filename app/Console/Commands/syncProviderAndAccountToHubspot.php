<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Traits\DendiApis;
use DB;
use Carbon\Carbon;
use DateTime;
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

class syncProviderAndAccountToHubspot extends Command
{
    use DendiApis;
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'get:syncProviderAndAccountToHubspot';

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
        // fetch providers and account sync to hubspot
        $limit  = 100;
        $offset = 1;
        do {
            $DFEProvidersResponse = $this->_getDendiData('api/v1/providers/?limit='.$limit.'&offset='.$offset);
            if (!empty($DFEProvidersResponse['response']['results'])) {
                $offset ++;
                $offset = $offset;

                foreach ($DFEProvidersResponse['response']['results'] as $providerKey => $providerValue) {

                    $companyMappingData = [];
                    $company            = [];
                    if (!empty($providerValue['accounts'])) {
                        foreach ($providerValue['accounts'] as $accountKey => $accountValue) {
                            $company[]            = $accountValue['name'];
                            $companyMappingData[] = [
                                "name"         => $accountValue['name'],
                                "account_uuid" => $accountValue['uuid'],
                            ];
                        }
                    }
                    $companyValue = implode(", ", $company);

                    $contactMappingData = [
                        'firstname'      => $providerValue["user"]['first_name'] ?? "",
                        'lastname'       => $providerValue["user"]['last_name']  ?? "",
                        'email'          => $providerValue["user"]['email']      ?? "",
                        'phone'          => $providerValue['phone_number']       ?? "",         
                        "provider_uuid"  => $providerValue['uuid']               ?? "",
                        "npi__"          => $providerValue['npi']                ?? "",
                        "company"        => !empty($companyValue) ? $companyValue : "",
                        'address'        => $providerValue['address1'] . ' '. $providerValue['address2'] ?? "",
                    ];



                    if (!empty($contactMappingData['email'])) {
                        $response  = $this->hubspotSearchContact('email', $contactMappingData['email'], ['firstname', 'lastname', 'company', 'npi__', 'email', 'state','account_uuid'] );
                        $response = json_decode($response[0], true);

                        if (empty($response['results'])) {
                            // Need to contact create
                            try {
                                $simplePublicObjectInputForCreate = new SimplePublicObjectInputForCreate([
                                    'associations' => null,
                                    'properties'   => $contactMappingData,
                                ]);
                                $contactCreateResponse = $client->crm()->contacts()->basicApi()->create($simplePublicObjectInputForCreate);
                                $contactCreateResponse = json_decode($contactCreateResponse);
                                if ($contactCreateResponse->id && !empty($contactCreateResponse->properties)) {
                                    if (!empty($companyMappingData)) {
                                        foreach ($companyMappingData as $companyMappingDataKey => $companyMappingDataValue) {
                                            $companyResponse = $this->hubspotSearchCompany('account_uuid', $companyMappingDataValue['account_uuid'], ['name']);
                                            $companyResponse = json_decode($companyResponse);
                                            if (empty($companyResponse->results)) {

                                                // create the company property and associated with the contact
                                                $companyInput = new SimplePublicObjectInput([
                                                    'properties' => $companyMappingDataValue
                                                ]);
                                                $newCompanyResponse = $client->crm()->companies()->basicApi()->create($companyInput);
                                                $newCompanyId       = json_decode($newCompanyResponse);
                                                if (!empty($newCompanyId->id)) {
                                                    $associationSpec2 = new AssociationsAssociationSpec([
                                                        'association_category' => 'HUBSPOT_DEFINED', 
                                                        'association_type_id'  => 279,  
                                                    ]);
                                                    $associationResponse = $client->crm()->associations()->v4()->basicApi()->create('contacts', $contactCreateResponse->id, 'companies', $newCompanyId->id, [$associationSpec2]);
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
                                                // if company alredy exit only need to associated with contact.
                                                $companyId    = $companyResponse->results[0]->id;
                                                if (!empty($companyId)) {
                                                    $associationSpec2 = new AssociationsAssociationSpec([
                                                        'association_category' => 'HUBSPOT_DEFINED', 
                                                        'association_type_id'  => 279,  
                                                    ]);
                                                    $associationResponse = $client->crm()->associations()->v4()->basicApi()->create('contacts', $contactCreateResponse->id, 'companies', $companyId, [$associationSpec2]);
                                                    $associationResponse = json_decode($associationResponse);
                                                    $associationId       = $associationResponse->toObjectTypeId;
                                                    if (empty($associationId)) {
                                                        \Log::info("Error during hubspot contacts updation and companies associations ");
                                                        \Log::info($associationResponse);
                                                    }
                                                }else{
                                                    \Log::info("Error companyResponse ");
                                                    \Log::info($companyResponse);
                                                }

                                            }
                                        }
                                    }else{
                                        \Log::info('Error account/company mapping data not found. ');
                                        \Log::info($companyMappingData);
                                    }
                                }else{
                                    \Log::info('Error during provider/contact create in hubspot. ');
                                    \Log::info($contactCreateResponse);
                                }
                            } catch (ApiException $e) {
                                echo "Exception when calling basic_api->create: ", $e->getMessage();
                            }
                        }else {
                            // skip no need to update
                        }
                    }else{
                        $response  = $this->hubspotSearchContact('provider_uuid', $contactMappingData['provider_uuid'], ['firstname', 'lastname', 'company', 'npi__', 'email', 'state','account_uuid'] );
                        $response = json_decode($response[0], true);

                        if (empty($response['results'])) {
                            // Need to contact create
                            try {
                                $simplePublicObjectInputForCreate = new SimplePublicObjectInputForCreate([
                                    'associations' => null,
                                    'properties'   => $contactMappingData,
                                ]);
                                $contactCreateResponse = $client->crm()->contacts()->basicApi()->create($simplePublicObjectInputForCreate);
                                $contactCreateResponse = json_decode($contactCreateResponse);
                                if ($contactCreateResponse->id && !empty($contactCreateResponse->properties)) {
                                    if (!empty($companyMappingData)) {
                                        foreach ($companyMappingData as $companyMappingDataKey => $companyMappingDataValue) {
                                            $companyResponse = $this->hubspotSearchCompany('account_uuid', $companyMappingDataValue['account_uuid'], ['name']);
                                            $companyResponse = json_decode($companyResponse);
                                            if (empty($companyResponse->results)) {

                                                // create the company property and associated with the contact
                                                $companyInput = new SimplePublicObjectInput([
                                                    'properties' => $companyMappingDataValue
                                                ]);
                                                $newCompanyResponse = $client->crm()->companies()->basicApi()->create($companyInput);
                                                $newCompanyId       = json_decode($newCompanyResponse);
                                                if (!empty($newCompanyId->id)) {
                                                    $associationSpec2 = new AssociationsAssociationSpec([
                                                        'association_category' => 'HUBSPOT_DEFINED', 
                                                        'association_type_id'  => 279,  
                                                    ]);
                                                    $associationResponse = $client->crm()->associations()->v4()->basicApi()->create('contacts', $contactCreateResponse->id, 'companies', $newCompanyId->id, [$associationSpec2]);
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
                                                // if company alredy exit only need to associated with contact.
                                                $companyId    = $companyResponse->results[0]->id;
                                                if (!empty($companyId)) {
                                                    $associationSpec2 = new AssociationsAssociationSpec([
                                                        'association_category' => 'HUBSPOT_DEFINED', 
                                                        'association_type_id'  => 279,  
                                                    ]);
                                                    $associationResponse = $client->crm()->associations()->v4()->basicApi()->create('contacts', $contactCreateResponse->id, 'companies', $companyId, [$associationSpec2]);
                                                    $associationResponse = json_decode($associationResponse);
                                                    $associationId       = $associationResponse->toObjectTypeId;
                                                    if (empty($associationId)) {
                                                        \Log::info("Error during hubspot contacts updation and companies associations ");
                                                        \Log::info($associationResponse);
                                                    }
                                                }else{
                                                    \Log::info("Error companyResponse ");
                                                    \Log::info($companyResponse);
                                                }

                                            }
                                        }
                                    }else{
                                        \Log::info('Error account/company mapping data not found. ');
                                        \Log::info($companyMappingData);
                                    }
                                }else{
                                    \Log::info('Error during provider/contact create in hubspot. ');
                                    \Log::info($contactCreateResponse);
                                }
                            } catch (ApiException $e) {
                                echo "Exception when calling basic_api->create: ", $e->getMessage();
                            }
                        }else {
                            // skip no need to update
                        }
                    }        
                }

            }else{
                \Log::info("Dendi Providers Response Not Found. offset " . $offset);
                \Log::info($DFEProvidersResponse);
                $DFEProvidersResponse = '';
            }
        } while (!empty($DFEProvidersResponse));
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
