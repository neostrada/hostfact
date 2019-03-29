<?php

namespace Neostrada;

use Psr\Http\Message\ResponseInterface;

class Client
{
    const ENDPOINT = 'https://api.neostrada.com/api';

    const ALLOWED_YEARS = [1, 2, 3];

    /**
     * Guzzle Client.
     *
     * @var Http
     */
    private $client;

    /**
     * Country cache.
     *
     * @var array
     */
    private $countries = [];

    /**
     * Extension cache.
     *
     * @var array
     */
    private $extensions = [];

    /**
     * Domain cache.
     *
     * @var array
     */
    private $domain = [];

    /**
     * Holder cache.
     *
     * @var array
     */
    private $holders = [];

    /**
     * Client constructor.
     *
     * @param $key
     */
    public function __construct($key)
    {
        $this->client = new Http([
            'base_uri' => self::ENDPOINT,
            'headers' => [
                'Accept' => 'application/json',
                'Authorization' => "Bearer {$key}"
            ]
        ]);
    }

    /**
     * Get all holders.
     *
     * @param bool $refresh
     * @return array|mixed|null
     */
    public function getHolders($refresh = false)
    {
        if (!empty($this->holders) && !$refresh) {
            return $this->holders;
        }

        $rc = null;

        $response = $this->client->get('holders');

        if (($holders = $this->getResult($response)) && isset($holders['results'])) {
            $rc = $holders['results'];
        }

        return $rc;
    }

    /**
     * Create a new holder.
     *
     * @param $holder
     * @return null
     */
    public function createHolder($holder)
    {
        $rc = null;

        if ($countryId = $this->getCountryId($holder['country_code'])) {
            $holder['country_id'] = $countryId;
            $holder['is_module'] = true;

            if (isset($holder['company']) && empty($holder['company'])) {
                unset($holder['company']);
            }

            $response = $this->client->post('holders/add', $holder);

            if (($holder = $this->getResult($response)) && isset($holder['results']['holder_id'])) {
                // Add holder to the holder cache
                $this->holders[] = $holder['results'];

                $rc = $holder['results']['holder_id'];
            }
        }

        return $rc;
    }

    /**
     * Update a holder.
     *
     * @param $id
     * @param $holder
     * @return null
     */
    public function updateHolder($id, $holder)
    {
        $rc = null;

        if ($countryId = $this->getCountryId($holder['country_code'])) {
            $holder['country_id'] = $countryId;
            $holder['is_module'] = true;

            if (isset($holder['company']) && empty($holder['company'])) {
                unset($holder['company']);
            }

            $response = $this->client->patch("holders/edit/{$id}", $holder);

            if (($holder = $this->getResult($response)) && isset($holder['results']['holder_id'])) {
                // Add holder to the holder cache
                $this->holders[] = $holder['results'];

                $rc = $holder['results']['holder_id'];
            }
        }

        return $rc;
    }

    /**
     * Try to find the specified holder.
     *
     * @param $holderToFind
     * @return int|null
     */
    public function findHolderId($holderToFind)
    {
        $rc = null;

        if ($holders = $this->getHolders()) {
            foreach ($holders as $holder) {
                $holderId = $holder['holder_id'];

                $holder = [
                    'company' => $holder['company'],
                    'firstname' => $holder['firstname'],
                    'lastname' => $holder['lastname'],
                    'street' => $holder['street'],
                    'zipcode' => $holder['zipcode'],
                    'city' => $holder['city'],
                    'email' => $holder['email']
                ];

                if ($holder == $holderToFind) {
                    $rc = $holderId;
                    break;
                }
            }
        }

        return $rc;
    }

    /**
     * Update the holders of a domain.
     *
     * @param $domain
     * @param $holders
     * @return bool|null
     */
    public function updateDomainHolders($domain, $holders)
    {
        $rc = null;

        if ($domain = $this->getDomain($domain)) {
            $domainId = $domain['id'];

            $response = $this->client->patch("domain/edit/{$domainId}", [
                'holder' => $holders
            ]);

            if ($this->success($response)) {
                $rc = true;
            }
        }

        return $rc;
    }

    /**
     * Get the nameservers of a domain.
     *
     * @param $domain
     * @return array
     */
    public function getNamesevers($domain)
    {
        $rc = null;

        if ($domain = $this->getDomain($domain)) {
            $dnsId = $domain['dns_id'];

            $response = $this->client->get("nameservers/{$dnsId}");

            if (($nameservers = $this->getResult($response)) && isset($nameservers['results'])) {
                $rc = $nameservers['results'];
            }
        }

        return $rc;
    }

    /**
     * Add nameservers to a domain.
     *
     * @param $domain
     * @param $nameservers
     * @return bool
     */
    public function addNameservers($domain, $nameservers)
    {
        $rc = null;

        if ($domain = $this->getDomain($domain)) {
            $dnsId = $domain['dns_id'];

            $response = $this->client->post("nameservers/add/{$dnsId}", [
                'content' => $nameservers
            ]);

            if ($this->success($response)) {
                $rc = true;
            }
        }

        return $rc;
    }

    /**
     * Delete the specified nameservers for the specified domain.
     *
     * @param $domain
     * @param $nameservers
     * @return bool|null
     */
    public function deleteNameservers($domain, $nameservers)
    {
        $rc = null;

        if ($domain = $this->getDomain($domain)) {
            $dnsId = $domain['dns_id'];

            $response = $this->client->delete("nameservers/delete/{$dnsId}", $nameservers);

            if ($this->success($response)) {
                $rc = true;
            }
        }

        return $rc;
    }

    /**
     * Delete all nameservers currently connected to the specified domain.
     *
     * @param $domain
     * @return bool|null
     */
    public function deleteCurrentNameservers($domain)
    {
        $rc = null;

        if ($nameservers = $this->getNamesevers($domain)) {
            $nameservers = array_map(function ($nameserver) {
                return ['record_id' => $nameserver['id']];
            }, $nameservers);

            if ($this->deleteNameservers($domain, $nameservers)) {
                $rc = true;
            }
        }

        return $rc;
    }

    /**
     * Get DNS records.
     *
     * @param $domain
     * @param array $exclude
     * @return array|null
     */
    public function getDnsRecords($domain, $exclude = ['SOA', 'NS'])
    {
        $rc = null;

        if ($domain = $this->getDomain($domain)) {
            $dnsId = $domain['dns_id'];

            $response = $this->client->get("dns/{$dnsId}");

            if (($records = $this->getResult($response)) && isset($records['results'])) {
                $rc = $records['results'];

                if (!empty($exclude)) {
                    $rc = array_filter($rc, function ($record) use ($exclude) {
                        return !in_array(strtoupper($record['type']), $exclude);
                    });
                }
            }
        }

        return $rc;
    }

    /**
     * @param $domain
     * @param $payload
     * @return bool
     */
    public function addDnsRecords($domain, $payload)
    {
        $rc = null;

        if ($domain = $this->getDomain($domain)) {
            $dnsId = $domain['dns_id'];

            $response = $this->client->post("dns/add/{$dnsId}", $payload);

            if ($this->success($response)) {
                $rc = true;
            }
        }

        return $rc;
    }

    /**
     * Update DNS records.
     *
     * @param $domain
     * @param $payload
     * @return bool
     */
    public function updateDnsRecords($domain, $payload)
    {
        $rc = null;

        if ($domain = $this->getDomain($domain)) {
            $dnsId = $domain['dns_id'];

            $response = $this->client->patch("dns/edit/{$dnsId}", $payload);

            if ($this->success($response)) {
                $rc = true;
            }
        }

        return $rc;
    }

    /**
     * Delete DNS records.
     *
     * @param $domain
     * @param $records
     * @return bool
     */
    public function deleteDnsRecords($domain, $records)
    {
        $rc = null;

        if ($domain = $this->getDomain($domain)) {
            $dnsId = $domain['dns_id'];

            $records = array_map(function ($record) {
                return ['record_id' => $record];
            }, $records);

            $response = $this->client->delete("dns/delete/{$dnsId}", $records);

            if ($this->success($response)) {
                $rc = true;
            }
        }

        return $rc;
    }

    /**
     * Get a domain.
     *
     * @param $domain
     * @param bool $refresh
     * @return array|mixed|null
     */
    public function getDomain($domain, $refresh = false)
    {
        if (!empty($this->domain) && !$refresh) {
            return $this->domain;
        }

        $rc = null;

        $response = $this->client->get("domain/{$domain}");

        if (($domain = $this->getResult($response)) && isset($domain['results'])) {
            $rc = $this->domain = $domain['results'];
        }

        return $rc;
    }

    /**
     * Get all domains.
     *
     * @return mixed|null
     */
    public function getDomains()
    {
        $rc = null;

        $response = $this->client->get('domains');

        if (($domains = $this->getResult($response)) && isset($domains['results'])) {
            $rc = $domains['results'];
        }

        return $rc;
    }

    /**
     * Delete a domain.
     *
     * @param $domain
     * @return bool
     */
    public function deleteDomain($domain)
    {
        $rc = null;

        $response = $this->client->delete("domain/delete/{$domain}");

        if ($this->success($response)) {
            $rc = true;
        }

        return $rc;
    }

    /**
     * Perform a WHOIS.
     *
     * @param $domain
     * @return array|mixed
     */
    public function whois($domain)
    {
        $rc = null;

        $response = $this->client->post('whois', [
            'domain' => $domain
        ]);

        if (($whois = $this->getResult($response)) && isset($whois['results'])) {
            $rc = $whois['results'];
        }

        return $rc;
    }

    /**
     * Check if the domain is available.
     *
     * @param $domain
     * @return bool
     */
    public function isAvailable($domain)
    {
        return ($whois = $this->whois($domain)) &&
            isset($whois[0]['available']) &&
            (bool) $whois[0]['available'] === true;
    }

    /**
     * Get all extensions.
     *
     * @param bool $refresh
     * @return array|mixed|null
     */
    public function getExtensions($refresh = false)
    {
        if (!empty($this->extensions) && !$refresh) {
            return $this->extensions;
        }

        $rc = null;

        $response = $this->client->get('extensions');

        if (($extensions = $this->getResult($response)) && isset($extensions['results'])) {
            $rc = $this->extensions = $extensions['results'];
        }

        return $rc;
    }

    /**
     * Get a single extension.
     *
     * @param $extension
     * @return array
     */
    public function getExtension($extension)
    {
        $rc = null;

        if ($extensions = $this->getExtensions()) {
            $extension = trim($extension, '.');

            // Get filter the array by the specified extension
            foreach ($extensions as $item) {
                if (isset($item['extension']) && $item['extension'] == $extension) {
                    $rc = $item;
                    break;
                }
            }
        }

        return $rc;
    }

    /**
     * Get countries.
     *
     * @param bool $refresh
     * @return mixed|null
     */
    public function getCountries($refresh = false)
    {
        if (!empty($this->countries) && !$refresh) {
            return $this->countries;
        }

        $rc = null;

        $response = $this->client->get('countries');

        if (($countries = $this->getResult($response)) && isset($countries['results'])) {
            $rc = $this->countries = $countries['results'];
        }

        return $rc;
    }

    /**
     * Get a country ID by its code.
     *
     * @param $countryCode
     * @return int
     */
    public function getCountryId($countryCode)
    {
        $rc = null;

        if ($countries = $this->getCountries()) {
            foreach ($countries as $country) {
                if (isset($country['code']) && $country['code'] == $countryCode && isset($country['country_id'])) {
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
     * @return null
     */
    public function getCountryById($countryId)
    {
        $rc = null;

        if ($countries = $this->getCountries()) {
            foreach ($countries as $country) {
                if (isset($country['country_id']) && $country['country_id'] == $countryId) {
                    $rc = $country;
                    break;
                }
            }
        }

        return $rc;
    }

    /**
     * Order a new domain.
     *
     * @param $domain
     * @param $holderId
     * @param $authCode
     * @param $years
     * @return bool
     */
    public function order($domain, $holderId, $years = 1, $authCode = '')
    {
        $rc = null;

        $parts = explode('.', $domain, 2);

        if (count($parts) == 2) {
            // Default to one year if the provided amount of years is no allowed
            if (!in_array($years, self::ALLOWED_YEARS)) {
                $years = 1;
            }

            if ($extension = $this->getExtension($parts[1])) {
                $payload = [
                    'extension_id' => $extension['extension_id'],
                    'domain' => $domain,
                    'holder_id' => $holderId,
                    'year' => $years
                ];

                // Add the auth code to the request to make it a transfer
                if (!empty($authCode)) {
                    $payload['authcode'] = $authCode;
                }

                $response = $this->client->post('orders/add/', $payload);

                if ($this->success($response)) {
                    $rc = true;
                }
            }
        }

        return $rc;
    }

    /**
     * Check if the request was successful.
     *
     * @param ResponseInterface $response
     * @param int $successCode
     * @return bool
     */
    private function success(ResponseInterface $response, $successCode = 200)
    {
        return $response->getStatusCode() === $successCode;
    }

    /**
     * Get the JSON result from the response, if there's any.
     *
     * @param ResponseInterface $response
     * @param bool $array
     * @return array|mixed
     */
    private function getResult(ResponseInterface $response, $array = true)
    {
        $rc = null;

        if ($this->success($response) && $result = json_decode($response->getBody()->getContents(), $array)) {
            $rc = $result;
        }

        return $rc;
    }
}
