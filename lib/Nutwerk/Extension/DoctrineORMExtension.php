<?php
namespace Nutwerk\Extension;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Configuration as DBALConfiguration;

use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Configuration as ORMConfiguration;
use Doctrine\ORM\Mapping\Driver\DriverChain;
use Doctrine\ORM\Mapping\Driver\AnnotationDriver;
use Doctrine\ORM\Mapping\Driver\XmlDriver;
use Doctrine\ORM\Mapping\Driver\YamlDriver;
use Doctrine\Common\Annotations\AnnotationReader;
use Doctrine\Common\Cache\ApcCache;
use Doctrine\Common\Cache\ArrayCache;
use Doctrine\Common\EventManager;

use Silex\Application;
use Silex\ExtensionInterface;

class DoctrineORMExtension implements ExtensionInterface
{
    public function register(Application $app)
    {        
        $dbal = $app['db'];

        if (!$dbal instanceof \Doctrine\DBAL\Connection) {
            throw new \InvalidArgumentException('$app[\'db\'] must be an instance of \Doctrine\DBAL\Connection'); 
        }
        
        $this->loadDoctrineConfiguration($app);
        $this->setOrmDefaults($app);
        $this->loadDoctrineOrm($app);

        $app['autoloader']->registerNamespace('Doctrine\\ORM', $app['db.orm.class_path']);
    }

    private function loadDoctrineOrm(Application $app)
    {
        $self = $this;
        $app['db.orm.em'] = $app->share(function() use($self, $app) {
            return EntityManager::create($app['db'], $app['db.orm.config']);
        });
    }

    private function setOrmDefaults(Application $app)
    {
        $defaults = array(
            'entities' => array(
                array(
                    'type' => 'annotation', 
                    'path' => 'Entity', 
                    'namespace' => 'Entity',
                )
            ),
            'proxies_dir'           => 'cache/doctrine/Proxy',
            'proxies_namespace'     => 'DoctrineProxy',
            'auto_generate_proxies' => true,
        );
        foreach ($defaults as $key => $value) {
            if (!isset($app['db.orm.' . $key])) {
                $app['db.orm.'.$key] = $value;
            }
        }
    }

    public function loadDoctrineConfiguration(Application $app)
    {
        $app['db.orm.config'] = $app->share(function() use($app) {

            $config = new ORMConfiguration;
            $cache = new ArrayCache;
            $config->setMetadataCacheImpl($cache);
            $config->setQueryCacheImpl($cache);

            $chain = new DriverChain;
            foreach((array)$app['db.orm.entities'] as $entity) {
                switch($entity['type']) {
                    case 'annotation':
                        $reader = new AnnotationReader;
                        $reader->setDefaultAnnotationNamespace('Doctrine\\ORM\\Mapping\\');
                        $reader->setAnnotationNamespaceAlias('Doctrine\\ORM\\Mapping\\', 'orm');
                        $driver = new AnnotationDriver($reader, (array)$entity['path']);
                        $chain->addDriver($driver, $entity['namespace']);
                        break;
                    case 'yml':
                        $driver = new YamlDriver((array)$entity['path']);
                        $driver->setFileExtension('.yml');
                        $chain->addDriver($driver, $entity['namespace']);
                        break;
                    case 'xml':
                        $driver = new XmlDriver((array)$entity['path'], $entity['namespace']);
                        $driver->setFileExtension('.xml');
                        $chain->addDriver($driver, $entity['namespace']);
                        break;
                    default:
                        throw new \InvalidArgumentException(sprintf('"%s" is not a recognized driver', $entity['type']));
                        break;
                }
                $app['autoloader']->registerNamespace($entity['namespace'], $entity['path']);
            }
            $config->setMetadataDriverImpl($chain);

            $config->setProxyDir($app['db.orm.proxies_dir']);
            $config->setProxyNamespace($app['db.orm.proxies_namespace']);
            $config->setAutoGenerateProxyClasses($app['db.orm.auto_generate_proxies']);

            return $config;
        });
    }
}