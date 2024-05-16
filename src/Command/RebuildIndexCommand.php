<?php

namespace CIHub\Bundle\SimpleRESTAdapterBundle\Command;
gc_enabled();

use CIHub\Bundle\SimpleRESTAdapterBundle\Elasticsearch\Index\IndexPersistenceService;
use CIHub\Bundle\SimpleRESTAdapterBundle\Exception\NotFoundException;
use CIHub\Bundle\SimpleRESTAdapterBundle\Manager\IndexManager;
use CIHub\Bundle\SimpleRESTAdapterBundle\Reader\ConfigReader;
use CIHub\Bundle\SimpleRESTAdapterBundle\Repository\DataHubConfigurationRepository;
use Doctrine\DBAL\Exception;
use Elastic\Elasticsearch\Exception\ClientResponseException;
use Elastic\Elasticsearch\Exception\MissingParameterException;
use Elastic\Elasticsearch\Exception\ServerResponseException;
use Pimcore\Bundle\DataHubBundle\Configuration;
use Pimcore\Model\Asset;
use Pimcore\Model\Asset\Folder;
use Pimcore\Model\DataObject;
use Pimcore\Model\Element\ElementInterface;
use Pimcore\Model\Version;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand('datahub:index:rebuild')]
class RebuildIndexCommand extends Command
{
    public const CHUNK_SIZE = 100;

    public const TYPE_ASSET = 'asset';
    public const TYPE_OBJECT = 'object';

    public function __construct(
        private readonly IndexManager                   $indexManager,
        private readonly IndexPersistenceService        $indexPersistenceService,
        private readonly DataHubConfigurationRepository $dataHubConfigurationRepository,
    ) {
        parent::__construct();
    }
    protected function configure(): void
    {
        $this
            ->setDescription('Rebuild index')
            ->addArgument(
                'name', InputArgument::REQUIRED, 'Specify configuration name',
            )
        ;
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $endpointName = $input->getArgument('name');
        $output->writeln('Starting index rebuilding for configuration: '.$endpointName);

        try {
            $configuration = $this->dataHubConfigurationRepository->findOneByName($endpointName);

            if ($configuration instanceof Configuration) {

                $configReader = new ConfigReader($configuration->getConfiguration());
                $this->cleanAliases($configReader);

                if ($configReader->isAssetIndexingEnabled()) {
                    $this->rebuildType(self::TYPE_ASSET, $endpointName, $output);
                }
                if ($configReader->isObjectIndexingEnabled()) {
                    $this->rebuildType(self::TYPE_OBJECT, $endpointName, $output);
                }
            }
        } catch (\Exception $e) {
            throw $e;
        }

        $output->writeln('Peak usage: ' . memory_get_peak_usage() / 1024 / 1024 . ' MBs');

        return Command::SUCCESS;;
    }

    /**
     * @throws ClientResponseException
     * @throws ServerResponseException
     * @throws MissingParameterException
     */
    public function cleanAliases(ConfigReader $configReader): void
    {
        $indices = $this->indexManager->getAllIndexNames($configReader);
        foreach ($indices as $alias) {
            $index = $this->indexManager->findIndexNameByAlias($alias);
            $newIndexName = $this->getNewIndexName($index);
            if ($this->indexPersistenceService->indexExists($newIndexName)) {
                $this->indexPersistenceService->deleteIndex($newIndexName);
            }
            $mapping = $this->indexPersistenceService->getMapping($index)[$index]['mappings'];
            $this->indexPersistenceService->createIndex($newIndexName, $mapping);

            $index = null;
            $mapping = null;
            $newIndexName = null;
            unset($index, $mapping, $newIndexName);
            gc_collect_cycles();
        }

        $indices = null;
        unset($indices);
    }

    /**
     * @throws Exception
     */
    private function rebuildType(string $type, string $endpointName, OutputInterface $output): void
    {
        $asset = new Asset();
        $sql = "SELECT id FROM {$type}s";
        $totalRecords = $asset->getDao()->db->fetchNumeric("SELECT COUNT(id) FROM {$type}s")[0];
        $batchSize = self::CHUNK_SIZE;
        $totalBatches = ceil($totalRecords / $batchSize);
        for ($i = 0; $i < $totalBatches; $i++) {
            $this->doBatch($i, $batchSize, $sql, $asset, $type, $output, $endpointName);
            gc_collect_cycles();
        }
        $conn = null;
        unset($conn);
        gc_collect_cycles();
    }

    private function enqueueParentFolders(
        ?ElementInterface $element,
        string $folderClass,
        string $indexName,
        string $endpointName
    ): void {
        while ($element instanceof $folderClass && 1 !== $element->getId()) {
            try {
                $this->indexPersistenceService->update(
                    $element,
                    $endpointName,
                    $indexName
                );
            } catch (\Exception $e) {
            }
            $element = $element->getParent();
            gc_collect_cycles();
        }
        $element = null;
        unset($element);
        gc_collect_cycles();
    }

    public function getNewIndexName(string $index): string
    {
        return str_ends_with($index, '-odd') ? str_replace('-odd', '', $index).'-even' : str_replace('-even', '', $index).'-odd';
    }

    private function getElement(int $id, string $type): Asset|DataObject
    {
        $asset = match($type){
            'asset' => new Asset(),
            'object' => new DataObject()
        };
        $asset->getDao()->getById($id);

        return $asset;
    }

    /**
     * @param int $i
     * @param int $batchSize
     * @param string $sql
     * @param Asset $asset
     * @param string $type
     * @param OutputInterface $output
     * @param string $endpointName
     * @return void
     * @throws Exception
     */
    private function doBatch(int $i, int $batchSize, string $sql, Asset $asset, string $type, OutputInterface $output, string $endpointName): void
    {
        $offset = $i * $batchSize;
        $batchQuery = $sql . " LIMIT $batchSize OFFSET $offset";
        $batchResults = $asset->getDao()->db->fetchAllAssociative($batchQuery);
        foreach ($batchResults as $result) {
            $id = (int)$result['id'];
            $element = $this->getElement($id, $type);
            $elementType = $element instanceof Asset ? 'asset' : 'object';
            try {
                $output->writeln(sprintf("Indexing element %s (%s)", $elementType, $id));
                $indexName = $this->indexManager->getIndexName($element, $endpointName);
                $this->indexPersistenceService->update(
                    $element,
                    $endpointName,
                    $indexName
                );
                $folderClass = $element instanceof DataObject ? DataObject\Folder::class : Folder::class;
                $this->enqueueParentFolders($element, $folderClass, $indexName, $endpointName);
                $indexName = null;
                unset($indexName);

            } catch (\Exception $e) {
                $output->writeln("Error: " . $e->getMessage());
            }

            $result = null;
            $element = null;
            unset($result, $element);
            $output->writeln('Usage: ' . memory_get_usage() / 1024 / 1024 . ' MBs');
        }
        $batchResults = null;
        unset($batchResults);
    }
}