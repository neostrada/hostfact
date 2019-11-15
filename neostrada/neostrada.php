<?php

require_once '3rdparty/domain/IRegistrar.php';
require_once '3rdparty/domain/standardfunctions.php';
require_once 'vendor/autoload.php';
require_once 'Http.php';
require_once 'Client.php';

use Neostrada\Client;

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
     * Check if a domain is available.
     *
     * @param $domain
     * @return bool
     */
    public function checkDomain($domain)
    {
        $client = new Client($this->Password);

        return $client->isAvailable($domain);
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
        $client = new Client($this->Password);

        $rc = false;

        if ($ownerHandle = $this->getHandle($whois, HANDLE_OWNER)) {
            $rc = $client->order($domain, $ownerHandle);
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
        $client = new Client($this->Password);

        $rc = false;

        if ($ownerHandle = $this->getHandle($whois, HANDLE_OWNER)) {
            $rc = $client->order($domain, $ownerHandle, 1, $authCode);
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
        $client = new Client($this->Password);

        $rc = false;

        if ($client->deleteDomain($domain)) {
            $rc = true;
        }

        return $rc;
    }


    /**
     * Get information about the specified domain.
     *
     * @param $domain
     * @return array|bool
     * @throws Exception
     */
    public function getDomainInformation($domain)
    {
        $client = new Client($this->Password);

        $rc = false;

        if ($domain = $client->getDomain($domain)) {
            $rc = [
                'Domain' => $domain['description'],
                'Information' => [
                    'expiration_date' => (new DateTime($domain['paid_until']))->format('Y-m-d'),
                    'registration_date' => (new DateTime($domain['start_date']))->format('Y-m-d')
                ]
            ];
        } else {
            $this->Error[] = 'Could not fetch domain';
        }

        return $rc;
    }

    /**
     * Get a list of all domains.
     *
     * @param string $contactHandle
     * @return array
     * @throws Exception
     */
    function getDomainList($contactHandle = '') {
        $client = new Client($this->Password);

        $rc = [];

        if ($domains = $client->getDomains()) {
            foreach ($domains as $domain) {
                if (!$domain['is_external']) {
                    $rc[] = [
                        'Domain' => $domain['description'],
                        'Information' => [
                            'expiration_date' => (new DateTime($domain['paid_until']))->format('Y-m-d'),
                            'registration_date' => (new DateTime($domain['start_date']))->format('Y-m-d')
                        ]
                    ];
                }
            }
        } else {
            $this->Error[] = 'Could not retrieve domains';
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
        $client = new Client($this->Password);

        $this->Error[] = 'Could not change the auto renew status';

        if ($autorenew) {
            $rc = $client->reactivateDomain($domain);
        } else {
            $rc = $client->deleteDomain($domain);
        }

        return $rc;
    }

    /**
     * Get transfer token.
     *
     * @param $domain
     * @return bool|string
     */
    public function getToken($domain)
    {
        $client = new Client($this->Password);

        $this->Error[] = 'Could not fetch transfer token';

        $domain = $client->getDomain($domain);

        if ($domain && isset($domain['auth_code'])) {
            return $domain['auth_code'];
        }

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
        $client = new Client($this->Password);

        $hostfactDomains = [];

        if ($domains = $client->getDomains()) {
            foreach ($domains as $domain) {
                // Only continue when the domain is not external
                if (!$domain['is_external']) {
                    $fetchedDomain = $domain['description'];

                    // Domain is found
                    if (isset($domainsToSync[$fetchedDomain])) {
                        if (isset($domain['paid_until']) && !empty($domain['paid_until'])) {
                            $expiresAt = DateTime::createFromFormat(DATE_ATOM, $domain['paid_until']);

                            $hostfactDomains[$fetchedDomain] = [
                                'Status' => 'success',
                                'Information' => [
                                    'expiration_date' => $expiresAt->format('Y-m-d')
                                ]
                            ];
                        } else {
                            $hostfactDomains[$fetchedDomain] = [
                                'Status' => 'error',
                                'Error_msg' => 'Domain not invoiced yet'
                            ];
                        }

                        // Remove the found domain from the list in order to check which
                        // domains could not be found
                        unset($domainsToSync[$fetchedDomain]);
                    }
                }
            }
        }

        // Some domains could not be found
        if (!empty($domainsToSync)) {
            foreach ($domainsToSync as $domain => $domainToSync) {
                $hostfactDomains[$domain] = [
                    'Status' => 'error',
                    'Error_msg' => 'Domain not found'
                ];
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
        $client = new Client($this->Password);

        $holders = [];

        if (isset($whois->ownerRegistrarHandles['neostrada'])) {
            $holders['registrar'] = $whois->ownerRegistrarHandles['neostrada'];
        }

        if (isset($whois->adminRegistrarHandles['neostrada'])) {
            $holders['admin'] = $whois->adminRegistrarHandles['neostrada'];
        }

        if (isset($whois->techRegistrarHandles['neostrada'])) {
            $holders['tech'] = $whois->techRegistrarHandles['neostrada'];
        }

        $rc = false;

        if (!empty($holders) && $client->updateDomainHolders($domain, $holders)) {
            $rc = true;
        } else {
            $this->Error[] = 'Could not update domain holders.';
        }

        return $rc;
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
        $client = new Client($this->Password);

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

        $holderId = $client->createHolder([
            'firstname' => $whois->{$prefix.'Initials'},
            'lastname' => $whois->{$prefix.'SurName'},
            'phone_number' => $whois->{$prefix.'PhoneNumber'},
            'street' => $whois->{$prefix.'Address'},
            'zipcode' => $whois->{$prefix.'ZipCode'},
            'city' => $whois->{$prefix.'City'},
            'country_code' => $whois->{$prefix.'Country'},
            'email' => $whois->{$prefix.'EmailAddress'},
        ]);

        $rc = false;

        if ($holderId) {
            $rc = $holderId;
        } else {
            $this->Error[] = 'Could not create contact';
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
    public function updateContact($handle, $whois, $type = HANDLE_OWNER) {
        $client = new Client($this->Password);

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

        $holderId = $client->updateHolder($handle, [
            'company' => $whois->{$prefix.'CompanyName'},
            'firstname' => $whois->{$prefix.'Initials'},
            'lastname' => $whois->{$prefix.'SurName'},
            'phone_number' => $whois->{$prefix.'PhoneNumber'},
            'street' => $whois->{$prefix.'Address'},
            'zipcode' => $whois->{$prefix.'ZipCode'},
            'city' => $whois->{$prefix.'City'},
            'country_code' => $whois->{$prefix.'Country'},
            'email' => $whois->{$prefix.'EmailAddress'},
        ]);

        $rc = false;

        if ($holderId) {
            $rc = true;
        } else {
            $this->Error[] = 'Could not update contact';
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
        $client = new Client($this->Password);

        $whois = new whois();

        if ($holders = $client->getHolders() && $client->getCountries()) {
            foreach ($holders as $holder) {
                if (
                    $holder['holder_id'] == $handle &&
                    $country = $client->getCountryById($holder['country_id'])
                ) {
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
        } else {
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
        $client = new Client($this->Password);

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

        $holderId = $client->findHolderId([
            'company' => $whois->{$prefix.'CompanyName'},
            'firstname' => $whois->{$prefix.'Initials'},
            'lastname' => $whois->{$prefix.'SurName'},
            'street' => $whois->{$prefix.'Address'},
            'zipcode' => $whois->{$prefix.'ZipCode'},
            'city' => $whois->{$prefix.'City'},
            'email' => $whois->{$prefix.'EmailAddress'}
        ]);

        $rc = 0;

        if ($holderId) {
            $rc = $holderId;
        }

        return $rc;
    }

    /**
     * Get a list of domain holders.
     *
     * @param string $surname
     * @return array
     */
    public function getContactList($surname = '')
    {
        $client = new Client($this->Password);

        $rc = [];

        if (($holders = $client->getHolders()) && $client->getCountries()) {
            foreach ($holders as $holder) {
                // Get the country code based on the country ID
                if ($country = $client->getCountryById($holder['country_id'])) {
                    $holder = [
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

                    if (!empty($surname)) {
                        if ($holder['SurName'] == $surname) {
                            $rc[] = $holder;
                        }
                    } else {
                        $rc[] = $holder;
                    }
                }
            }
        }

        return $rc;
    }

    /**
     * Update nameservers of a domain.
     *
     * @param $domain
     * @param array $nameservers
     * @return bool
     */
    public function updateNameServers($domain, $nameservers = []) {
        $client = new Client($this->Password);

        $rc = false;

        $client->deleteCurrentNameservers($domain);

        if ($client->addNameservers($domain, $nameservers)) {
            $rc = true;
        } else {
            $this->Error[] = 'Could not update nameservers';
        }

        return $rc;
    }

    /**
     * Get class version information.
     *
     * @return array()
     */
    static function getVersionInformation() {
        require_once('3rdparty/domain/neostrada/version.php');
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
}
