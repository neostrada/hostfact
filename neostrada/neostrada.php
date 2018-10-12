<?php

require_once 'vendor/autoload.php';
require_once '3rdparty/domain/IRegistrar.php';
require_once '3rdparty/domain/standardfunctions.php';

use GuzzleHttp\Client;
use GuzzleHttp\RequestOptions;

class Neostrada implements IRegistrar
{
    /**
     * API username.
     *
     * @var
     */
    public $User;

    /**
     * API password.
     *
     * @var
     */
    public $Password;

    /**
     * Error messages.
     *
     * @var array
     */
    public $Error = [];

    /**
     * Warning messages.
     *
     * @var array
     */
    public $Warning = [];

    /**
     * Success messages.
     *
     * @var array
     */
    public $Success = [];

    /**
     * Domain registration period. Also used when transferring a domain.
     *
     * @var int
     */
    public $Period = 1;

    /**
     * Domain handles stored in HostFact.
     *
     * @var array
     */
    public $registrarHandles = [];

    /**
     * This class name.
     *
     * @var string
     */
    private $className = __CLASS__;

    /**
     * GuzzleClient to connect to registrar API.
     *
     * @var Client
     */
    private $client;

    /**
     * Neostrada constructor.
     */
    public function __construct()
    {
        $this->client = new Client([
            'base_uri' => 'https://api.neostrada.com/api/'
        ]);
    }

    /**
     * Check if a domain is available.
     *
     * @param $domain
     * @return bool
     */
    public function checkDomain($domain)
    {
        $rc = false;

        try {
            $response = $this->client->post('whois', [
                RequestOptions::FORM_PARAMS => [
                    'domain' => $domain,
                    'access_token' => $this->Password
                ]
            ]);

            if ($response->getStatusCode() === 200) {
                $response = json_decode($response->getBody()->getContents(), true);

                if ($response['available'] == 210) {
                    $rc = true;
                }
            }
        } catch (Exception $exception) {}

        return $rc;
    }

    /**
     * Register a domain.
     *
     * @param $domain
     * @param array $nameservers
     * @param null $whois
     * @return bool
     */
    public function registerDomain($domain, $nameservers = [], $whois = null)
    {
        $rc = false;

        if ($ownerHandle = $this->getHandle($whois, HANDLE_OWNER)) {
            $rc = $this->placeOrder($domain, $ownerHandle);
        } else {
            $this->Error[] = sprintf('No owner contact given for domain %s', $domain);
        }

        if (!$rc) {
            $this->Error[] = sprintf('The domain %s could not be registered', $domain);
        } else {
            $this->Period = 1;
        }

        return $rc;
    }

    /**
     * Transfer a domain.
     *
     * @param $domain
     * @param array $nameservers
     * @param null $whois
     * @param string $authCode
     * @return bool
     */
    public function transferDomain($domain, $nameservers = [], $whois = null, $authCode = '')
    {
        $rc = false;

        if ($ownerHandle = $this->getHandle($whois, HANDLE_OWNER)) {
            $rc = $this->placeOrder($domain, $ownerHandle, $authCode);
        } else {
            $this->Error[] = sprintf('No owner contact given for domain %s', $domain);
        }

        if (!$rc) {
            $this->Error[] = sprintf('The domain %s could not be transferred', $domain);
        } else {
            $this->Period = 1;
        }

        return $rc;
    }

    /**
     * Delete a domain.
     *
     * @param $domain
     * @param string $type
     * @return bool
     */
    public function deleteDomain($domain, $type = 'end')
    {
        $rc = false;

        try {
            $response = $this->client->delete('domain/delete/'.$domain, [
                RequestOptions::QUERY => [
                    'access_token' => $this->Password
                ]
            ]);

            if ($response->getStatusCode() === 200) {
                if (
                    ($deletedDomain = json_decode($response->getBody()->getContents(), true)) &&
                    $deletedDomain['status'] == 'cancelled'
                ) {
                    $rc = true;
                }
            }
        } catch (Exception $exception) {}

        return $rc;
    }


    /**
     * Get information about the specified domain.
     *
     * @param $domain
     * @return bool
     */
    public function getDomainInformation($domain)
    {
        $this->Error[] = 'Retrieving information about a single domain is currently not supported';

        return false;
    }

    /**
     * Get a list of all domains.
     *
     * @param string $contactHandle
     * @return array|bool
     */
    function getDomainList($contactHandle = '') {
        $rc = [];

        try {
            $response = $this->client->get('domains', [
                RequestOptions::QUERY => [
                    'access_token' => $this->Password
                ]
            ]);

            if ($response->getStatusCode() === 200) {
                $domains = json_decode($response->getBody()->getContents(), true);

                foreach ($domains as $domain) {
                    if (!$domain['is_external']) {
                        $expirationDate = new DateTime($domain['paid_untill']);
                        $registrationDate = new DateTime($domain['start_date']);

                        $rc[] = [
                            'Domain' => $domain['description'],
                            'Information' => [
                                'expiration_date' => $expirationDate->format('Y-m-d'),
                                'registration_date' => $registrationDate->format('Y-m-d')
                            ]
                        ];
                    }
                }
            }
        } catch (Exception $exception) {}

        if (empty($rc)) {
            $rc = false;

            $this->Error[] = 'Domains could not be retrieved';
        }

        return $rc;
    }

    /**
     * Lock or unlock a domain.
     *
     * @param $domain
     * @param bool $lock
     * @return bool
     */
    public function lockDomain($domain, $lock = true)
    {
        $this->Error[] = 'Locking and unlocking domains is not supported';

        return false;
    }

    /**
     * Toggle automatic domain renewal.
     *
     * @param $domain
     * @param bool $autorenew
     * @return bool
     */
    public function setDomainAutoRenew($domain, $autorenew = true) {
        $this->Error[] = 'Changing the automatic renewal is not supported';

        return false;
    }

    /**
     * Get transfer token.
     *
     * @param $domain
     * @return bool|string
     */
    public function getToken($domain)
    {
        $this->Error[] = 'Fetching the transfer token is not supported';

        return false;
    }

    /**
     * Synchronize data from the registrar with HostFact.
     *
     * @param $domainsToSync
     * @return mixed
     */
    public function getSyncData($domainsToSync)
    {
        $hostfactDomains = [];

        try {
            $response = $this->client->get('domains', [
                RequestOptions::QUERY => [
                    'access_token' => $this->Password
                ]
            ]);

            if ($response->getStatusCode() === 200) {
                if ($fetchedDomains = json_decode($response->getBody()->getContents(), true)) {
                    foreach ($fetchedDomains as $fetchedDomain) {
                        // Only continue when the domain is not external
                        if (!$fetchedDomain['is_external']) {
                            $domain = $fetchedDomain['description'];

                            // Domain is found
                            if (isset($domainsToSync[$domain])) {
                                $expirationDate = new DateTime($fetchedDomain['paid_untill']);

                                $hostfactDomains[$domain]['Information'] = [
                                    'expiration_date' => $expirationDate->format('Y-m-d')
                                ];

                                $hostfactDomains[$domain]['Status'] = 'success';

                                // Remove the found domain from the list in order to check which
                                // domains could not be found
                                unset($domainsToSync[$domain]);
                            }
                        }
                    }
                }
            }
        } catch (Exception $exception) {}

        // Some domains could not be found
        if (!empty($domainsToSync)) {
            foreach ($domainsToSync as $domain => $domainToSync) {
                $hostfactDomains[$domain]['Status'] = 'error';
                $hostfactDomains[$domain]['Error_msg'] = 'Domain not found';
            }
        }

        return $hostfactDomains;
    }

    /**
     * Directly update domain WHOIS when the registrar doesn't support handles.
     *
     * @param $domain
     * @return bool
     */
    public function updateDomainWhois($domain, $whois)
    {
        $this->Error[] = 'Directly updating the WHOIS is not supported because the WHOIS information is managed through handles.';

        return false;
    }

    /**
     * Get domain handles from the registrar.
     *
     * @param $domain
     * @return bool
     */
    public function getDomainWhois($domain)
    {
        $this->Error[] = 'Fetching domain handles from the registrar is not supported';

        return false;
    }

    /**
     * Create a contact.
     *
     * @param $whois
     * @param $type
     * @return bool
     */
    public function createContact($whois, $type = HANDLE_OWNER)
    {
        $prefix = '';

        switch ($type) {
            case HANDLE_OWNER:
                $prefix = 'owner';
                break;

            case HANDLE_ADMIN:
                $prefix = 'admin';
                break;

            case HANDLE_TECH:
                $prefix = 'tech';
                break;
        }

        $rc = false;

        if ($countryId = $this->getCountryId($whois->{$prefix.'Country'})) {
            $data = [
                'firstname' => $whois->{$prefix.'Initials'},
                'lastname' => $whois->{$prefix.'SurName'},
                'phone_number' => $whois->{$prefix.'PhoneNumber'},
                'street' => $whois->{$prefix.'Address'},
                'zipcode' => $whois->{$prefix.'ZipCode'},
                'city' => $whois->{$prefix.'City'},
                'country_id' => $countryId,
                'email' => $whois->{$prefix.'EmailAddress'},
                'is_module' => true,
                'access_token' => $this->Password
            ];

            if ($companyName = $whois->{$prefix.'CompanyName'}) {
                $data['company'] = $companyName;
            }

            try {
                $response = $this->client->post('holders/add', [
                    RequestOptions::FORM_PARAMS => $data
                ]);

                if ($response->getStatusCode() === 200) {
                    if ($registrarHandle = json_decode($response->getBody()->getContents(), true)) {
                        $rc = $registrarHandle['holder_id'];
                    }
                }
            } catch (Exception $exception) {
                $this->Error[] = 'Contact could not be created. Please make sure that you have filled in all required fields: first name, last name, address, postal code, city, country, phone number, email address';
            }
        }

        return $rc;
    }

    /**
     * Update a contact.
     *
     * @param $handle
     * @param $whois
     * @param $type
     * @return bool
     */
    function updateContact($handle, $whois, $type = HANDLE_OWNER) {
        $prefix = '';

        switch ($type) {
            case HANDLE_OWNER:
                $prefix = 'owner';
                break;

            case HANDLE_ADMIN:
                $prefix = 'admin';
                break;

            case HANDLE_TECH:
                $prefix = 'tech';
                break;
        }

        $rc = false;

        $countryId = $this->getCountryId($whois->{$prefix.'Country'});

        if ($countryId > 0) {
            try {
                $response = $this->client->patch('holders/edit/'.$handle, [
                    RequestOptions::FORM_PARAMS => [
                        'company' => $whois->{$prefix.'CompanyName'},
                        'firstname' => $whois->{$prefix.'Initials'},
                        'lastname' => $whois->{$prefix.'SurName'},
                        'phone_number' => $whois->{$prefix.'PhoneNumber'},
                        'street' => $whois->{$prefix.'Address'},
                        'zipcode' => $whois->{$prefix.'ZipCode'},
                        'city' => $whois->{$prefix.'City'},
                        'country_id' => $countryId,
                        'email' => $whois->{$prefix.'EmailAddress'},
                        'is_module' => true,
                        'access_token' => $this->Password
                    ]
                ]);

                if ($response->getStatusCode() === 200) {
                    $rc = true;
                }
            } catch (Exception $exception) {}
        }

        if (!$rc) {
            $this->Error[] = 'Contact could not be updated';
        }

        return $rc;
    }

    /**
     * Get a contact based on the specified handle.
     *
     * @param $handle
     * @return whois
     */
    public function getContact($handle)
    {
        $fetchedContact = false;

        $whois = new whois();

        if ($countries = $this->getCountries()) {
            try {
                $response = $this->client->get('holders', [
                    RequestOptions::QUERY => [
                        'access_token' => $this->Password
                    ]
                ]);

                if ($response->getStatusCode() === 200) {
                    if ($holders = json_decode($response->getBody()->getContents(), true)) {
                        foreach ($holders as $holder) {
                            if (
                                $holder['holder_id'] == $handle &&
                                $country = $this->getCountryById($holder['country_id'], $countries)
                            ) {
                                $fetchedContact = true;

                                $whois->ownerCompanyName = $holder['company'];
                                $whois->ownerInitials = $holder['firstname'];
                                $whois->ownerSurName = $holder['lastname'];
                                $whois->ownerAddress = $holder['street'];
                                $whois->ownerZipCode = $holder['zipcode'];
                                $whois->ownerCity = $holder['city'];
                                $whois->ownerCountry = $country['code'];
                                $whois->ownerPhoneNumber = $holder['phone_number'];
                                $whois->ownerEmailAddress = $holder['email'];

                                break;
                            }
                        }
                    }
                }
            } catch (Exception $exception) {}
        }

        if (!$fetchedContact) {
            $this->Error[] = 'Contact could not be retrieved';
        }

        return $whois;
    }

    /**
     * Get the handle of a contact based on its email address.
     *
     * @param $whois
     * @param $type
     * @return int|mixed
     */
    public function getContactHandle($whois, $type = HANDLE_OWNER)
    {
        $prefix = '';

        switch ($type) {
            case HANDLE_OWNER:
                $prefix = 'owner';
                break;

            case HANDLE_ADMIN:
                $prefix = 'admin';
                break;

            case HANDLE_TECH:
                $prefix = 'tech';
                break;
        }

        $rc = 0;

        if ($handle = $this->getContactList($whois->{$prefix.'EmailAddress'})) {
            $rc = $handle['Handle'];
        }

        return $rc;
    }

    /**
     * Get a list of domains holder using the API.
     *
     * @param string $emailAddress
     * @return array
     */
    public function getContactList($emailAddress = '')
    {
        $rc = [];

        if ($countries = $this->getCountries()) {
            try {
                $response = $this->client->get('holders', [
                    RequestOptions::QUERY => [
                        'access_token' => $this->Password
                    ]
                ]);

                if ($response->getStatusCode() === 200) {
                    if ($holders = json_decode($response->getBody()->getContents(), true)) {
                        foreach ($holders as $holder) {
                            // Get the country code based on the country ID
                            if ($country = $this->getCountryById($holder['country_id'], $countries)) {
                                $mappedHolder = [
                                    'Handle' => $holder['holder_id'],
                                    'CompanyName' => $holder['company'],
                                    'Initials' => $holder['firstname'],
                                    'SurName' => $holder['lastname'],
                                    'PhoneNumber' => $holder['phone_number'],
                                    'Address' => $holder['street'],
                                    'ZipCode' => $holder['zipcode'],
                                    'City' => $holder['city'],
                                    'Country' => $country['code'],
                                    'EmailAddress' => $holder['email']
                                ];

                                if (!empty($emailAddress)) {
                                    if ($holder['email'] == $emailAddress) {
                                        $rc = $mappedHolder;
                                        break;
                                    }
                                } else {
                                    $rc[] = $mappedHolder;
                                }
                            }
                        }
                    }
                }
            } catch (Exception $exception) {}
        }

        return $rc;
    }

    /**
     * Update the nameservers for the given domain.
     *
     * @param string $domain The domain to be changed.
     * @param array $nameservers The new set of nameservers.
     * @return bool True if the update was succesfull; False otherwise;
     */
    function updateNameServers($domain, $nameservers = array()) {
        /**
         * Step 1) update nameservers for domain
         */
        $response 	= true;
        $error_msg 	= '';

        /**
         * Step 2) provide feedback to WeFact
         */
        if($response === true)
        {
            // Change nameservers is succesfull
            return true;
        }
        else
        {
            // Nameservers cannot be changed
            $this->Error[] 	= sprintf("YourName: Error while changing nameservers for '%s': %s", $domain, $error_msg);
            return false;
        }
    }

    /**
     * Get class version information.
     *
     * @return array()
     */
    static function getVersionInformation() {
        require_once("3rdparty/domain/neostrada/version.php"); //TODO: change your class name
        return $version;
    }

    /**
     * Get the handle ID from HostFact.
     *
     * @param $whois
     * @param $type
     * @return bool|int|string
     */
    private function getHandle($whois, $type)
    {
        $prefix = '';

        switch ($type) {
            case HANDLE_OWNER:
                $prefix = 'owner';
                break;

            case HANDLE_ADMIN:
                $prefix = 'admin';
                break;

            case HANDLE_TECH:
                $prefix = 'tech';
                break;
        }

        $rc = 0;

        if (!empty($prefix)) {
            // Check if the handle is stored in HostFact
            if (isset($whois->{$prefix.'RegistrarHandles'}[$this->className])) {
                $rc = $whois->{$prefix.'RegistrarHandles'}[$this->className];
            } elseif (!empty($whois->{$prefix.'EmailAddress'})) {
                // If it's not stored, fetch it using the API
                $handle = $this->getContactHandle($whois, $type);

                // If the handle doesn't exist anywhere, create it
                if (empty($handle)) {
                    $handle = $this->createContact($whois, $type);
                }

                // Let HostFact store the handle for quicker registrations and transfers
                $this->registrarHandles[$prefix] = $handle;

                $rc = $handle;
            }
        }

        return $rc;
    }

    /**
     * Get a country's ID by its country code.
     *
     * @param $countryCode
     * @param array $countries
     * @return int
     */
    private function getCountryId($countryCode, $countries = [])
    {
        $rc = 0;

        // Fetch countries from the API if they're not provided
        if (empty($countries)) {
            $countries = $this->getCountries();
        }

        if ($countries) {
            foreach ($countries as $country) {
                if ($country['code'] == $countryCode) {
                    $rc = $country['country_id'];
                    break;
                }
            }
        }

        return $rc;
    }

    /**
     * Get a country by its ID.
     *
     * @param $countryId
     * @return array|mixed
     */
    private function getCountryById($countryId, $countries = [])
    {
        $rc = [];

        // Fetch countries from the API if they're not provided
        if (empty($countries)) {
            $countries = $this->getCountries();
        }

        if ($countries) {
            foreach ($countries as $country) {
                if ($country['country_id'] == $countryId) {
                    $rc = $country;
                    break;
                }
            }
        }

        return $rc;
    }

    /**
     * Get a list of all available countries.
     *
     * @return array|mixed
     */
    private function getCountries()
    {
        $rc = [];

        try {
            $response = $this->client->get('countries', [
                RequestOptions::QUERY => [
                    'access_token' => $this->Password
                ]
            ]);

            if ($response->getStatusCode() === 200) {
                $rc = json_decode($response->getBody()->getContents(), true);
            }
        } catch (Exception $exception) {}

        return $rc;
    }

    /**
     * Get extensions.
     *
     * @param string $extensionToFind
     * @return array|mixed
     */
    private function getExtensions($extensionToFind = '')
    {
        $rc = [];

        try {
            $response = $this->client->get('extensions', [
                RequestOptions::QUERY => [
                    'access_token' => $this->Password
                ]
            ]);

            if ($response->getStatusCode() === 200) {
                if ($extensions = json_decode($response->getBody()->getContents(), true)) {
                    if (!empty($extensionToFind)) {
                        $extensionToFind = trim($extensionToFind, '.');

                        foreach ($extensions as $extension) {
                            if ($extension['extension'] == $extensionToFind) {
                                $rc = $extension;
                                break;
                            }
                        }
                    } else {
                        $rc = $extensions;
                    }
                }
            }
        } catch (Exception $exception) {}

        return $rc;
    }

    /**
     * Place a domain order.
     *
     * @param $domain
     * @param $handle
     * @param string $authCode
     * @return bool
     */
    private function placeOrder($domain, $handle, $authCode = '')
    {
        $rc = false;

        list($sld, $tld) = explode('.', $domain, 2);

        if ($extension = $this->getExtensions($tld)) {
            $data = [
                'extension_id' => $extension['extension_id'],
                'domain' => $domain,
                'holder_id' => $handle,
                'year' => 1,
                'access_token' => $this->Password
            ];

            if (!empty($authCode)) {
                $data['authcode'] = $authCode;
            }

            try {
                $response = $this->client->post('orders/add/', [
                    RequestOptions::FORM_PARAMS => $data
                ]);

                if ($response->getStatusCode() === 200) {
                    $rc = true;
                }
            } catch (Exception $exception) {}
        }

        return $rc;
    }
}
