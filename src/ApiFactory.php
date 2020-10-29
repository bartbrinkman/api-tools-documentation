<?php

/**
 * @see       https://github.com/laminas-api-tools/api-tools-documentation for the canonical source repository
 * @copyright https://github.com/laminas-api-tools/api-tools-documentation/blob/master/COPYRIGHT.md
 * @license   https://github.com/laminas-api-tools/api-tools-documentation/blob/master/LICENSE.md New BSD License
 */

namespace Laminas\ApiTools\Documentation;

use Laminas\ApiTools\Configuration\ModuleUtils as ConfigModuleUtils;
use Laminas\ApiTools\Provider\ApiToolsProviderInterface;
use Laminas\InputFilter\CollectionInputFilter;
use Laminas\InputFilter\InputFilterInterface;
use Laminas\ModuleManager\ModuleManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadataInfo;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class ApiFactory
{
    /**
     * @var ModuleManager
     */
    protected $moduleManager;

    /**
     * @var array
     */
    protected $config;

    /**
     * @var ConfigModuleUtils
     */
    protected $configModuleUtils;

    protected $entityManager;

    /**
     * @var array
     */
    protected $docs = [];

    protected $listenerCache = [];

    /**
     * @param ModuleManager $moduleManager
     * @param array $config
     * @param ConfigModuleUtils $configModuleUtils
     */
    public function __construct(ModuleManager $moduleManager, $config, ConfigModuleUtils $configModuleUtils, EntityManagerInterface $entityManager)
    {
        $this->moduleManager = $moduleManager;
        $this->config = $config;
        $this->configModuleUtils = $configModuleUtils;
        $this->entityManager = $entityManager;
    }

    /**
     * Create list of available API modules
     *
     * @return array
     */
    public function createApiList()
    {
        $apiToolsModules = [];
        $q = preg_quote('\\');
        foreach ($this->moduleManager->getModules() as $moduleName) {
            $module = $this->moduleManager->getModule($moduleName);
            if ($module instanceof ApiToolsProviderInterface) {
                $versionRegex = '#' . preg_quote($moduleName) . $q . 'V(?P<version>[^' . $q . ']+)' . $q . '#';
                $versions = [];
                $serviceConfigs = [];
                if ($this->config['api-tools-rest']) {
                    $serviceConfigs = array_merge($serviceConfigs, $this->config['api-tools-rest']);
                }
                if ($this->config['api-tools-rpc']) {
                    $serviceConfigs = array_merge($serviceConfigs, $this->config['api-tools-rpc']);
                }

                foreach ($serviceConfigs as $serviceName => $serviceConfig) {
                    if (! preg_match($versionRegex, $serviceName, $matches)) {
                        continue;
                    }
                    $version = $matches['version'];
                    if (! in_array($version, $versions)) {
                        $versions[] = $version;
                    }
                }

                $apiToolsModules[] = [
                    'name'     => $moduleName,
                    'versions' => $versions,
                ];
            }
        }
        return $apiToolsModules;
    }

    /**
     * Create documentation details for a given API module and version
     *
     * @param string $apiName
     * @param int|string $apiVersion
     * @return Api
     */
    public function createApi($apiName, $apiVersion = 1)
    {
        $api = new Api;

        $api->setVersion($apiVersion);
        $api->setName($apiName);

        $serviceConfigs = [];
        if (! empty($this->config['api-tools-rest'])) {
            $serviceConfigs = array_merge($serviceConfigs, $this->config['api-tools-rest']);
        }
        if (! empty($this->config['api-tools-rpc'])) {
            $serviceConfigs = array_merge($serviceConfigs, $this->config['api-tools-rpc']);
        }

        // Sort services by name
        ksort($serviceConfigs);

        foreach ($serviceConfigs as $serviceName => $serviceConfig) {
            if (strpos($serviceName, $apiName . '\\') === 0
                && strpos($serviceName, '\V' . $api->getVersion() . '\\')
                && isset($serviceConfig['service_name'])
            ) {
                $service = $this->createService($api, $serviceConfig['service_name']);
                if ($service) {
                    $api->addService($service);
                }
            }
        }

        $docsArray = $this->getDocumentationConfig($api->getName());
        if (isset($docsArray['name'])) {
            $api->setName($docsArray['name']);
        }
        if (isset($docsArray['description'])) {
            $api->setDescription($docsArray['description']);
        }

        return $api;
    }

    /**
     * Create documentation details for a given service in a given version of
     * an API module
     *
     * @param Api $api
     * @param string $serviceName
     * @return Service
     */
    public function createService(Api $api, $serviceName)
    {
        $service = new Service();
        $service->setApi($api);

        $serviceData = null;
        $isRest      = false;
        $isRpc       = false;
        $hasSegments = false;
        $hasFields   = false;

        foreach ($this->config['api-tools-rest'] as $serviceClassName => $restConfig) {
            if ((strpos($serviceClassName, $api->getName() . '\\') === 0)
                && isset($restConfig['service_name'])
                && ($restConfig['service_name'] === $serviceName)
                && (strstr($serviceClassName, '\\V' . $api->getVersion() . '\\') !== false)
            ) {
                $serviceData = $restConfig;
                $isRest = true;
                $hasSegments = true;
                break;
            }
        }

        if (! $serviceData) {
            foreach ($this->config['api-tools-rpc'] as $serviceClassName => $rpcConfig) {
                if ((strpos($serviceClassName, $api->getName() . '\\') === 0)
                    && isset($rpcConfig['service_name'])
                    && ($rpcConfig['service_name'] === $serviceName)
                    && (strstr($serviceClassName, '\\V' . $api->getVersion() . '\\') !== false)
                ) {
                    $serviceData = $rpcConfig;
                    $serviceData['action'] = $this->marshalActionFromRouteConfig(
                        $serviceName,
                        $serviceClassName,
                        $rpcConfig
                    );
                    $isRpc = true;
                    break;
                }
            }
        }

        if (! $serviceData || ! isset($serviceClassName)) {
            return false;
        }

        $authorizations = $this->getAuthorizations($serviceClassName);

        $docsArray = $this->getDocumentationConfig($api->getName());

        $service->setName($serviceData['service_name']);
        if (isset($docsArray[$serviceClassName]['description'])) {
            $service->setDescription($docsArray[$serviceClassName]['description']);
        }

        if (!$this->listenerCache) {
            $results = [];
            $directoryIterator = new RecursiveDirectoryIterator(APPLICATION_PATH);
            $iterator = new RecursiveIteratorIterator($directoryIterator, RecursiveIteratorIterator::SELF_FIRST);
            foreach ($iterator as $file) {
                if ($file->isDir()) {
                    continue;
                }
                if (strstr($file->getPath(), 'vendor/')) {
                    continue;
                }
                if (!\preg_match('/\.php$/i', $file->getFileName())) {
                    continue;
                }

                $results[] = $file->getPathName();
            }

            $collection = [];
            foreach ($results as $filepath) {
                $tokens = \token_get_all(\file_get_contents($filepath));
                foreach ($tokens as $index => $token) {
                    if (!isset($token[1])) {
                        continue;
                    }
                    if ($token[1] === 'attach') {
                        foreach (array_slice($tokens, $index) as $_index => $_token) {
                            if (!is_array($_token) && $_token === ';') {
                                $collection[] = array_reduce(
                                    array_slice($tokens, $index, $_index),
                                    function ($carry, $item) {
                                        if (is_array($item)) {
                                            $item = $item[1];
                                        }
                                        return trim($carry .= $item);
                                    }
                                );
                                continue 2;
                            }
                        }
                    }
                }
            }

            foreach ($collection as $index => $entry) {
                $collection[$index] = preg_replace_callback('/\\\\([a-z0-9\\\\]+)::class/i', function ($matches) {
                    return '\''.str_replace('\\', '\\\\', $matches[1]).'\'';
                }, $entry);
            }

            foreach ($collection as $index => $entry) {
                if (preg_match('/attach\(\'(.+?)\',\'?(.+?)\'?,\[\$this,\'(.+?)\'/i', $entry, $match)) {
                    $target = \str_replace('\\\\', '\\', $match[1]);

                    $this->listenerCache[$target][$match[2]][] = $match[3];
                }
            }
        }

        if (isset($serviceData['listener']) && isset($this->listenerCache[$serviceData['listener']])) {
            $service->setListeners($this->listenerCache[$serviceData['listener']]);
        }

        if (isset($docsArray[$serviceClassName]['tags'])) {
            $tags = $docsArray[$serviceClassName]['tags'];
            if (!is_array($tags)) {
                $tags = array_map('trim', explode(',', $tags));
            }
            $service->setTags($tags);
        }

        $route = $this->config['router']['routes'][$serviceData['route_name']]['options']['route'] ?? null;
        if (!$route) {
            foreach ($this->config['router']['routes'] as $routeConfig) {
                $route = $routeConfig['child_routes'][$serviceData['route_name']]['options']['route'] ?? null;
            }
        }

        $service->setRoute(str_replace('[/v:version]', $api->getVersion() >= 2 ? '/v'.$api->getVersion() : '', $route)); // remove internal version prefix, hacky
        if ($isRpc) {
            $hasSegments = $this->hasOptionalSegments($route);
        }

        if (isset($serviceData['route_identifier_name'])) {
            $service->setRouteIdentifierName($serviceData['route_identifier_name']);
        }

        $fields = [];
        if (isset($this->config['api-tools-content-validation'][$serviceClassName])) {
            $validators = $this->config['api-tools-content-validation'][$serviceClassName];
            foreach ($validators as $validatorKey => $validatorName) {
                if (isset($this->config['input_filter_specs'][$validatorName])) {
                    foreach ($this->mapFields($this->config['input_filter_specs'][$validatorName]) as $fieldData) {
                        $fields[$validatorKey][] = $this->getField($fieldData);
                    }
                    $hasFields = true;
                }
            }
        }

        if (isset($docsArray[$serviceClassName]['collection']['name'])) {
            $service->setOperationsName($docsArray[$serviceClassName]['collection']['name']);
        }

        if (isset($docsArray[$serviceClassName]['entity']['name'])) {
            $service->setEntityOperationsName($docsArray[$serviceClassName]['entity']['name']);
        }

        $baseOperationData = (isset($serviceData['collection_http_methods']))
            ? $serviceData['collection_http_methods']
            : $serviceData['http_methods'];

        $ops = [];
        foreach ($baseOperationData as $httpMethod) {
            $op = new Operation();
            $op->setHttpMethod($httpMethod);

            if ($isRest) {
                $description = isset($docsArray[$serviceClassName]['collection'][$httpMethod]['description'])
                    ? $docsArray[$serviceClassName]['collection'][$httpMethod]['description']
                    : '';
                $op->setDescription($description);

                $requestDescription = isset($docsArray[$serviceClassName]['collection'][$httpMethod]['request'])
                    ? $docsArray[$serviceClassName]['collection'][$httpMethod]['request']
                    : '';
                $op->setRequestDescription($requestDescription);

                $responseDescription = isset($docsArray[$serviceClassName]['collection'][$httpMethod]['response'])
                    ? $docsArray[$serviceClassName]['collection'][$httpMethod]['response']
                    : '';

                $op->setResponseDescription($responseDescription);
                $op->setRequiresAuthorization(
                    isset($authorizations['collection'][$httpMethod])
                    ? $authorizations['collection'][$httpMethod]
                    : false
                );

                $op->setResponseStatusCodes($this->getStatusCodes(
                    $httpMethod,
                    false,
                    $hasFields,
                    $op->requiresAuthorization()
                ));
            }

            if ($isRpc) {
                $description = isset($docsArray[$serviceClassName][$httpMethod]['description'])
                    ? $docsArray[$serviceClassName][$httpMethod]['description']
                    : '';
                $op->setDescription($description);

                $requestDescription = isset($docsArray[$serviceClassName][$httpMethod]['request'])
                    ? $docsArray[$serviceClassName][$httpMethod]['request']
                    : '';
                $op->setRequestDescription($requestDescription);

                $responseDescription = isset($docsArray[$serviceClassName][$httpMethod]['response'])
                    ? $docsArray[$serviceClassName][$httpMethod]['response']
                    : '';
                $op->setResponseDescription($responseDescription);

                $op->setRequiresAuthorization(
                    isset($authorizations['actions'][$serviceData['action']][$httpMethod])
                    ? $authorizations['actions'][$serviceData['action']][$httpMethod]
                    : false
                );
                $op->setResponseStatusCodes($this->getStatusCodes(
                    $httpMethod,
                    $hasSegments,
                    $hasFields,
                    $op->requiresAuthorization()
                ));
            }

            $ops[] = $op;
        }

        if (isset($serviceData['entity_class'])) {
            $metadata = $this->entityManager->getClassMetadata($serviceData['entity_class']);
            foreach ($metadata->fieldMappings as $fieldMapping) {
                $field = new Field();
                $field->setName($fieldMapping['fieldName']);
                $field->setFieldType($fieldMapping['type']);
                $fields['doctrine'][] = $field;
            }
            foreach ($metadata->associationMappings as $associationMapping) {
                if (!in_array($associationMapping['type'], [
                    ClassMetadataInfo::ONE_TO_ONE,
                    ClassMetadataInfo::MANY_TO_ONE,
                ])) {
                    continue;
                }
                $field = new Field();
                $field->setName($associationMapping['fieldName']);
                $field->setFieldType('object');
                $fields['doctrine'][] = $field;
            }
        }

        $service->setFields($fields);
        $service->setOperations($ops);

        if (isset($serviceData['entity_http_methods'])) {
            $ops = [];
            foreach ($serviceData['entity_http_methods'] as $httpMethod) {
                $op = new Operation();
                $op->setHttpMethod($httpMethod);

                $description = isset($docsArray[$serviceClassName]['entity'][$httpMethod]['description'])
                    ? $docsArray[$serviceClassName]['entity'][$httpMethod]['description']
                    : '';
                $op->setDescription($description);

                $requestDescription = isset($docsArray[$serviceClassName]['entity'][$httpMethod]['request'])
                    ? $docsArray[$serviceClassName]['entity'][$httpMethod]['request']
                    : '';
                $op->setRequestDescription($requestDescription);

                $responseDescription = isset($docsArray[$serviceClassName]['entity'][$httpMethod]['response'])
                    ? $docsArray[$serviceClassName]['entity'][$httpMethod]['response']
                    : '';
                $op->setResponseDescription($responseDescription);

                $op->setRequiresAuthorization(
                    isset($authorizations['entity'][$httpMethod])
                    ? $authorizations['entity'][$httpMethod]
                    : false
                );
                $op->setResponseStatusCodes($this->getStatusCodes(
                    $httpMethod,
                    true,
                    $hasFields,
                    $op->requiresAuthorization()
                ));
                $ops[] = $op;
            }
            $service->setEntityOperations($ops);
        }

        if (isset($this->config['api-tools-content-negotiation']['accept_whitelist'][$serviceClassName])) {
            $service->setRequestAcceptTypes(
                $this->config['api-tools-content-negotiation']['accept_whitelist'][$serviceClassName]
            );
        }

        if (isset($this->config['api-tools-content-negotiation']['content_type_whitelist'][$serviceClassName])) {
            $service->setRequestContentTypes(
                $this->config['api-tools-content-negotiation']['content_type_whitelist'][$serviceClassName]
            );
        }

        return $service;
    }

    /**
     * @param array $fields
     * @param string $prefix To unwind nesting of fields
     * @return array
     */
    private function mapFields(array $fields, $prefix = '')
    {
        if (isset($fields['name'])) {
            // detect usage of "name" as a field group name
            if (is_array($fields['name']) && isset($fields['name']['name'])) {
                return $this->mapFields($fields['name'], 'name');
            }

            if ($prefix) {
                $fields['name'] = sprintf('%s/%s', $prefix, $fields['name']);
            }
            return [$fields];
        }

        $flatFields = [];

        foreach ($fields as $idx => $field) {
            if (isset($field['type'], $field['input_filter'])
                && ($field['type'] === CollectionInputFilter::class
                    || is_subclass_of($field['type'], CollectionInputFilter::class))
            ) {
                $filteredFields = array_diff_key($field['input_filter'], ['type' => 0]);
                $fullindex = $prefix ? sprintf('%s/%s[]', $prefix, $idx) : $idx . '[]';
                $flatFields = array_merge($flatFields, $this->mapFields($filteredFields, $fullindex));
                continue;
            }

            if (isset($field['type'])
                && is_subclass_of($field['type'], InputFilterInterface::class)
            ) {
                $filteredFields = array_diff_key($field, ['type' => 0]);
                $fullindex = $prefix ? sprintf('%s/%s', $prefix, $idx) : $idx;
                $flatFields = array_merge($flatFields, $this->mapFields($filteredFields, $fullindex));
                continue;
            }

            $flatFields = array_merge($flatFields, $this->mapFields($field, $prefix));
        }

        return $flatFields;
    }

    /**
     * @param array $fieldData
     * @return Field
     */
    private function getField(array $fieldData)
    {
        $field = new Field();

        $field->setName($fieldData['name']);
        if (isset($fieldData['description'])) {
            $field->setDescription($fieldData['description']);
        }

        if (isset($fieldData['type'])) {
            $field->setType($fieldData['type']);
        }

        if (isset($fieldData['field_type'])) {
            $field->setFieldType($fieldData['field_type']);
        }

        if (isset($fieldData['example'])) {
            $field->setExample($fieldData['example']);
        }

        $required = isset($fieldData['required']) ? (bool) $fieldData['required'] : false;
        $field->setRequired($required);

        if (isset($fieldData['validators'])) {
            foreach ($fieldData['validators'] as $validator) {
                if ($validator['name'] === 'Becky\Validator\ExistentialQuantification') {
                    $field->setDescription($field->getDescription().' Can also be `null`. '.PHP_EOL.'        + id (number)');
                }
                if ($validator['name'] === 'Becky\Validator\AssertSuperadmin') {
                    $field->setDescription($field->getDescription().' This is an internal value and cannot be changed.');
                }
                if ($validator['name'] === \Laminas\Validator\StringLength::class) {
                    if (isset($validator['options']['max'])) {
                        $field->setDescription($field->getDescription().sprintf(' Maximum of %s characters.', $validator['options']['max']));
                    }
                }
                if ($validator['name'] === 'Becky\Validator\IsBoolean') {
                    $field->setFieldType('bool');
                }
            }
        }

        return $field;
    }

    /**
     * Retrieve the documentation for a given API module
     *
     * @param string $apiName
     * @return array
     */
    protected function getDocumentationConfig($apiName)
    {
        if (isset($this->docs[$apiName])) {
            return $this->docs[$apiName];
        }

        $moduleConfigPath = $this->configModuleUtils->getModuleConfigPath($apiName);
        $docConfigPath = dirname($moduleConfigPath) . '/documentation.config.php';
        if (file_exists($docConfigPath)) {
            $this->docs[$apiName] = include $docConfigPath;
        } else {
            $this->docs[$apiName] = [];
        }

        return $this->docs[$apiName];
    }

    /**
     * Retrieve authorization data for the given service
     *
     * @param string $serviceName
     * @return array
     */
    protected function getAuthorizations($serviceName)
    {
        if (! isset($this->config['api-tools-mvc-auth']['authorization'][$serviceName])) {
            return [];
        }
        return $this->config['api-tools-mvc-auth']['authorization'][$serviceName];
    }

    /**
     * Determine the RPC action name based on the routing configuration
     *
     * @param string $serviceName
     * @param string $serviceClassName
     * @param array $config
     * @return string
     */
    protected function marshalActionFromRouteConfig($serviceName, $serviceClassName, array $config)
    {
        if (! isset($config['route_name'])) {
            return $serviceName;
        }
        if (! isset($this->config['router']['routes'][$config['route_name']])) {
            return $serviceName;
        }
        $route = $this->config['router']['routes'][$config['route_name']];
        if (! isset($route['options']['defaults']['action'])) {
            return $serviceName;
        }

        return $route['options']['defaults']['action'];
    }

    protected function hasOptionalSegments($route)
    {
        return preg_match('#\[.*?:.+\]#', $route);
    }

    protected function getStatusCodes($httpMethod, $hasOptionalSegments, $hasValidation, $requiresAuthorization)
    {
        $statusCodes = [];

        switch ($httpMethod) {
            case 'GET':
                array_push($statusCodes, ['code' => '200', 'message' => 'OK']);
                if ($hasOptionalSegments) {
                    array_push($statusCodes, ['code' => '404', 'message' => 'Not Found']);
                }
                break;
            case 'DELETE':
                array_push($statusCodes, ['code' => '204', 'message' => 'No Content']);
                if ($hasOptionalSegments) {
                    array_push($statusCodes, ['code' => '404', 'message' => 'Not Found']);
                }
                break;
            case 'POST':
                array_push($statusCodes, ['code' => '201', 'message' => 'Created']);
                if ($hasOptionalSegments) {
                    array_push($statusCodes, ['code' => '404', 'message' => 'Not Found']);
                }
                if ($hasValidation) {
                    array_push($statusCodes, ['code' => '400', 'message' => 'Client Error']);
                    array_push($statusCodes, ['code' => '415', 'message' => 'Unsupported Media Type']);
                    array_push($statusCodes, ['code' => '422', 'message' => 'Unprocessable Entity']);
                }
                break;
            case 'PATCH':
            case 'PUT':
                array_push($statusCodes, ['code' => '200', 'message' => 'OK']);
                if ($hasOptionalSegments) {
                    array_push($statusCodes, ['code' => '404', 'message' => 'Not Found']);
                }
                if ($hasValidation) {
                    array_push($statusCodes, ['code' => '400', 'message' => 'Client Error']);
                    array_push($statusCodes, ['code' => '415', 'message' => 'Unsupported Media Type']);
                    array_push($statusCodes, ['code' => '422', 'message' => 'Unprocessable Entity']);
                }
                break;
        }

        if ($requiresAuthorization) {
            array_push($statusCodes, ['code' => '401', 'message' => 'Unauthorized']);
            array_push($statusCodes, ['code' => '403', 'message' => 'Forbidden']);
        }

        array_push($statusCodes, ['code' => '406', 'message' => 'Not Acceptable']);

        return $statusCodes;
    }
}
