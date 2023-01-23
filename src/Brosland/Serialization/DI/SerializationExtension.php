<?php
declare(strict_types=1);

namespace Brosland\Serialization\DI;

use JMS\Serializer\EventDispatcher\EventDispatcher;
use JMS\Serializer\EventDispatcher\EventSubscriberInterface;
use JMS\Serializer\Handler\HandlerRegistry;
use JMS\Serializer\Handler\SubscribingHandlerInterface;
use JMS\Serializer\Naming\IdenticalPropertyNamingStrategy;
use JMS\Serializer\Serializer;
use JMS\Serializer\SerializerBuilder;
use Nette\DI\CompilerExtension;
use Nette\DI\Definitions\ServiceDefinition;
use Nette\DI\Definitions\Statement;
use Nette\Schema\Expect;
use Nette\Schema\Schema;

final class SerializationExtension extends CompilerExtension
{
	public function __construct(
		private readonly bool $debugMode,
		private readonly string $tempDir
	)
	{
	}

	public function getConfigSchema(): Schema
	{
		$mappingType = Expect::arrayOf(Expect::string()->required(), Expect::string())
			->required();

		return Expect::structure(['mapping' => $mappingType])->castTo('array');
	}

	public function loadConfiguration(): void
	{
		parent::loadConfiguration();

		/** @var array<string,mixed> $config */
		$config = $this->getConfig();
		$builder = $this->getContainerBuilder();

		$namingStrategy = new Statement(IdenticalPropertyNamingStrategy::class);

		$serializerBuilder = (new ServiceDefinition())
			->setType(SerializerBuilder::class)
			->addSetup('setCacheDir', [$this->tempDir . '/cache/serializer'])
			->addSetup('setDebug', [$this->debugMode])
			->addSetup('setPropertyNamingStrategy', [$namingStrategy]);

		foreach ($config['mapping'] as $namespace => $dir) {
			$serializerBuilder->addSetup('addMetadataDir', [$dir, $namespace]);
		}

		$serializer = (new ServiceDefinition())
			->setType(Serializer::class)
			->setFactory($this->prefix('@serializerBuilder::build'));

		$builder->addDefinition($this->prefix('serializerBuilder'), $serializerBuilder);
		$builder->addDefinition($this->prefix('serializer'), $serializer);
	}

	public function beforeCompile(): void
	{
		parent::beforeCompile();

		$builder = $this->getContainerBuilder();

		// setup handlers
		$handlerRegistry = (new ServiceDefinition())
			->setType(HandlerRegistry::class);

		$builder->addDefinition($this->prefix('handlerRegistry'), $handlerRegistry);

		/** @var ServiceDefinition $handler */
		foreach ($builder->findByType(SubscribingHandlerInterface::class) as $handler) {
			$handlerRegistry->addSetup('registerSubscribingHandler', [$handler]);
		}

		// setup event subscribers
		$eventDispatcher = (new ServiceDefinition())
			->setType(EventDispatcher::class);

		$builder->addDefinition($this->prefix('eventDispatcher'), $eventDispatcher);

		/** @var ServiceDefinition $subscriber */
		foreach ($builder->findByType(EventSubscriberInterface::class) as $subscriber) {
			$eventDispatcher->addSetup('addSubscriber', [$subscriber]);
		}

		/** @var ServiceDefinition $serializerBuilder */
		$serializerBuilder = $builder->getDefinitionByType(SerializerBuilder::class);
		$serializerBuilder->setArgument('handlerRegistry', $handlerRegistry);
		$serializerBuilder->setArgument('eventDispatcher', $eventDispatcher);
	}
}