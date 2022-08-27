<?php

namespace Swoolegento\Cli\App;

use Magento\Framework\App\Area;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\Interception\Cache\CompiledConfig;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\App\State;
use Magento\Framework\Filesystem\DriverPool;
use Magento\Framework\Interception\ObjectManager\ConfigInterface;
use Magento\Framework\App\ObjectManager\Environment;
use Magento\Framework\Config\File\ConfigFilePool;
use Magento\Framework\Code\GeneratedFiles;

class ObjectManagerFactory extends \Magento\Framework\App\ObjectManagerFactory
{
    /**
     * @var \Magento\Framework\ObjectManager\DefinitionFactory
     */
    private $definitionFactory;

    /**
     * @var \Magento\Framework\ObjectManager\Relations\Runtime
     */
    private $relations;

    /**
     * @var \Magento\Framework\ObjectManager\Definition\Runtime
     */
    private $definitions;

    /**
     * @var \Magento\Framework\App\ObjectManager\Environment\Developer|\Magento\Framework\App\ObjectManager\Environment\Compiled
     */
    private $env;

    /**
     * @var \Magento\Framework\Interception\ObjectManager\Config\Developer|\Magento\Framework\Interception\ObjectManager\Config\Compiled
     */
    private $diConfig;

    /**
     * Constructor
     *
     * @param DirectoryList $directoryList
     * @param DriverPool $driverPool
     * @param ConfigFilePool $configFilePool
     * @param \Magento\Framework\Interception\Config\Config $config
     */
    public function __construct(
        DirectoryList $directoryList,
        DriverPool $driverPool,
        ConfigFilePool $configFilePool,
        \Magento\Framework\Interception\Config\Config $config
    ) {
        parent::__construct($directoryList, $driverPool, $configFilePool);

        $this->definitionFactory = new \Magento\Framework\ObjectManager\DefinitionFactory(
            $this->driverPool->getDriver(DriverPool::FILE),
            $this->directoryList->getPath(DirectoryList::GENERATED_CODE)
        );

        $this->definitions = $this->definitionFactory->createClassDefinition();
        $this->relations = $this->definitionFactory->createRelations();

        /** @var EnvironmentFactory $envFactory */
        $envFactory = new $this->envFactoryClassName($this->relations, $this->definitions);
        /** @var EnvironmentInterface $env */
        $this->env = $envFactory->createEnvironment();

        /** @var ConfigInterface $diConfig */
        $this->diConfig = $this->env->getDiConfig();

        $this->diConfig->setInterceptionConfig($config);
    }

    /**
     * Create ObjectManager
     *
     * @param array $arguments
     * @return \Magento\Framework\ObjectManagerInterface
     *
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    public function create(array $arguments)
    {
        $writeFactory = new \Magento\Framework\Filesystem\Directory\WriteFactory($this->driverPool);
        /** @var \Magento\Framework\Filesystem\Driver\File $fileDriver */
        $fileDriver = $this->driverPool->getDriver(DriverPool::FILE);
        $lockManager = new \Magento\Framework\Lock\Backend\FileLock($fileDriver, BP);
        $generatedFiles = new GeneratedFiles($this->directoryList, $writeFactory, $lockManager);
        $generatedFiles->cleanGeneratedFiles();

        $deploymentConfig = $this->createDeploymentConfig($this->directoryList, $this->configFilePool, $arguments);
        $arguments = array_merge($deploymentConfig->get(), $arguments);

        $appMode = isset($arguments[State::PARAM_MODE]) ? $arguments[State::PARAM_MODE] : State::MODE_DEFAULT;
        $booleanUtils = new \Magento\Framework\Stdlib\BooleanUtils();
        $argInterpreter = $this->createArgumentInterpreter($booleanUtils);
        $argumentMapper = new \Magento\Framework\ObjectManager\Config\Mapper\Dom($argInterpreter);

        if ($this->env->getMode() != Environment\Compiled::MODE) {
            $configData = $this->_loadPrimaryConfig($this->directoryList, $this->driverPool, $argumentMapper, $appMode);
            if ($configData) {
                $this->diConfig->extend($configData);
            }
        }

        // set cache profiler decorator if enabled
        if (\Magento\Framework\Profiler::isEnabled()) {
            $cacheFactoryArguments = $this->diConfig->getArguments(\Magento\Framework\App\Cache\Frontend\Factory::class);
            $cacheFactoryArguments['decorators'][] = [
                'class' => \Magento\Framework\Cache\Frontend\Decorator\Profiler::class,
                'parameters' => ['backendPrefixes' => ['Zend_Cache_Backend_', 'Cm_Cache_Backend_']],
            ];
            $cacheFactoryConfig = [
                \Magento\Framework\App\Cache\Frontend\Factory::class => ['arguments' => $cacheFactoryArguments]
            ];
            $this->diConfig->extend($cacheFactoryConfig);
        }

        $sharedInstances = [
            \Magento\Framework\App\DeploymentConfig::class => $deploymentConfig,
            \Magento\Framework\App\Filesystem\DirectoryList::class => $this->directoryList,
            \Magento\Framework\Filesystem\DirectoryList::class => $this->directoryList,
            \Magento\Framework\Filesystem\DriverPool::class => $this->driverPool,
            \Magento\Framework\ObjectManager\RelationsInterface::class => $this->relations,
            \Magento\Framework\Interception\DefinitionInterface::class => $this->definitionFactory->createPluginDefinition(),
            \Magento\Framework\ObjectManager\ConfigInterface::class => $this->diConfig,
            \Magento\Framework\Interception\ObjectManager\ConfigInterface::class => $this->diConfig,
            \Magento\Framework\ObjectManager\DefinitionInterface::class => $this->definitions,
            \Magento\Framework\Stdlib\BooleanUtils::class => $booleanUtils,
            \Magento\Framework\ObjectManager\Config\Mapper\Dom::class => $argumentMapper,
            \Magento\Framework\ObjectManager\ConfigLoaderInterface::class => $this->env->getObjectManagerConfigLoader(),
            $this->_configClassName => $this->diConfig,
        ];
        $arguments['shared_instances'] = &$sharedInstances;
        $this->factory = $this->env->getObjectManagerFactory($arguments);

        /** @var \Magento\Framework\ObjectManagerInterface $objectManager */
        $objectManager = new $this->_locatorClassName($this->factory, $this->diConfig, $sharedInstances);

        $this->factory->setObjectManager($objectManager);

        $generatorParams = $this->diConfig->getArguments(\Magento\Framework\Code\Generator::class);
        /** Arguments are stored in different format when DI config is compiled, thus require custom processing */
        $generatedEntities = isset($generatorParams['generatedEntities']['_v_'])
            ? $generatorParams['generatedEntities']['_v_']
            : (isset($generatorParams['generatedEntities']) ? $generatorParams['generatedEntities'] : []);
        $this->definitionFactory->getCodeGenerator()
            ->setObjectManager($objectManager)
            ->setGeneratedEntities($generatedEntities);

        if ($this->env->getMode() == 'compiled') {
            $this->configureObjectManagerCompiled($sharedInstances);
        } else {
            $this->configureObjectManager($sharedInstances);
        }

        return $objectManager;
    }

    /**
     * @param $sharedInstances
     */
    public function configureObjectManager(&$sharedInstances)
    {
        $originalSharedInstances = $sharedInstances;
        $objectManager = ObjectManager::getInstance();
        $sharedInstances[\Magento\Framework\ObjectManager\ConfigLoaderInterface::class] = $objectManager
            ->get(\Magento\Framework\App\ObjectManager\ConfigLoader::class);

        $this->diConfig->setCache(
            $objectManager->get(\Magento\Framework\App\ObjectManager\ConfigCache::class)
        );

        $objectManager->configure(
            $objectManager
                ->get(\Magento\Framework\App\ObjectManager\ConfigLoader::class)
                ->load(Area::AREA_GLOBAL)
        );
        $objectManager->get(\Magento\Framework\Config\ScopeInterface::class)
            ->setCurrentScope('global');
        /** Reset the shared instances once interception config is set so classes can be intercepted if necessary */
        $sharedInstances = $originalSharedInstances;
        $sharedInstances[\Magento\Framework\ObjectManager\ConfigLoaderInterface::class] = $objectManager
            ->get(\Magento\Framework\App\ObjectManager\ConfigLoader::class);
    }

    /**
     * @param $sharedInstances
     */
    public function configureObjectManagerCompiled(&$sharedInstances)
    {
        $objectManager = ObjectManager::getInstance();

        $objectManager->configure(
            $objectManager
                ->get(\Magento\Framework\ObjectManager\ConfigLoaderInterface::class)
                ->load(Area::AREA_GLOBAL)
        );
        $objectManager->get(\Magento\Framework\Config\ScopeInterface::class)
            ->setCurrentScope('global');
        $sharedInstances[\Magento\Framework\Interception\PluginList\PluginList::class] = $objectManager->create(
            \Magento\Framework\Interception\PluginListInterface::class,
            ['cache' => $objectManager->get(\Magento\Framework\App\Interception\Cache\CompiledConfig::class)]
        );
        $objectManager
            ->get(\Magento\Framework\App\Cache\Manager::class)
            ->setEnabled([CompiledConfig::TYPE_IDENTIFIER], true);
    }
}
