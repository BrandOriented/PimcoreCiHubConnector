<?php

/**
 * This source file is subject to the GNU General Public License version 3 (GPLv3)
 * For the full copyright and license information, please view the LICENSE.md and gpl-3.0.txt
 * files that are distributed with this source code.
 *
 * @license    https://choosealicense.com/licenses/gpl-3.0/ GNU General Public License v3.0
 * @copyright  Copyright (c) 2023 Brand Oriented sp. z o.o. (https://brandoriented.pl)
 * @copyright  Copyright (c) 2021 CI HUB GmbH (https://ci-hub.com)
 */

namespace CIHub\Bundle\SimpleRESTAdapterBundle\Controller;

use CIHub\Bundle\SimpleRESTAdapterBundle\Extractor\LabelExtractorInterface;
use CIHub\Bundle\SimpleRESTAdapterBundle\Manager\IndexManager;
use CIHub\Bundle\SimpleRESTAdapterBundle\Model\Event\ConfigurationEvent;
use CIHub\Bundle\SimpleRESTAdapterBundle\Model\Event\GetModifiedConfigurationEvent;
use CIHub\Bundle\SimpleRESTAdapterBundle\Provider\AssetProvider;
use CIHub\Bundle\SimpleRESTAdapterBundle\Reader\ConfigReader;
use CIHub\Bundle\SimpleRESTAdapterBundle\Repository\DataHubConfigurationRepository;
use CIHub\Bundle\SimpleRESTAdapterBundle\SimpleRESTAdapterEvents;
use Pimcore\Bundle\AdminBundle\Controller\AdminAbstractController;
use Pimcore\Bundle\DataHubBundle\Configuration;
use Pimcore\Bundle\DataHubBundle\Controller\ConfigController as BaseConfigController;
use Pimcore\Bundle\DataHubBundle\WorkspaceHelper;
use Pimcore\Model\Asset\Image\Thumbnail\Config\Listing;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[Route('/admin/rest/config', name: 'datahub_rest_adapter_config_', options: ['expose' => true])]
final class ConfigController extends AdminAbstractController
{
    public function __construct(
        private readonly AssetProvider $assetProvider)
    {
    }

    #[Route('/delete', name: 'delete', methods: ['GET'])]
    public function deleteAction(
        DataHubConfigurationRepository $configRepository,
        EventDispatcherInterface $eventDispatcher,
        Request $request,
    ): JsonResponse {
        $this->checkPermission(BaseConfigController::CONFIG_NAME);

        try {
            $name = $request->get('name');
            $configuration = $configRepository->findOneByName($name);

            if (!$configuration instanceof Configuration) {
                return new JsonResponse(['error' => sprintf('No DataHub configuration found for name "%s".', $name)]);
            }

            $config = $configuration->getConfiguration();
            $preDeleteEvent = new ConfigurationEvent($config);
            $eventDispatcher->dispatch($preDeleteEvent, SimpleRESTAdapterEvents::CONFIGURATION_PRE_DELETE);

            WorkspaceHelper::deleteConfiguration($configuration);
            $configuration->delete();

            $postDeleteEvent = new ConfigurationEvent($config);
            $eventDispatcher->dispatch($postDeleteEvent, SimpleRESTAdapterEvents::CONFIGURATION_POST_DELETE);

            return $this->json(['success' => true]);
        } catch (\Exception $exception) {
            return $this->json(['success' => false, 'message' => $exception->getMessage()]);
        }
    }

    #[Route('/get', name: 'get', methods: ['GET'])]
    public function getAction(DataHubConfigurationRepository $configRepository, Request $request): JsonResponse
    {
        $this->checkPermission(BaseConfigController::CONFIG_NAME);

        $configName = $request->get('name');
        $configuration = $configRepository->findOneByName($configName);

        if (!$configuration instanceof Configuration) {
            return new JsonResponse(['error' => sprintf('No DataHub configuration found for name "%s".', $configName)]);
        }

        // Add endpoint routes to current config
        $configReader = new ConfigReader($configuration->getConfiguration());
        $configReader->add([
            'swaggerUrl' => $this->getEndpoint('datahub_rest_adapter_swagger_ui'),
            'treeItemsUrl' => $this->getEndpoint('datahub_rest_endpoints_tree_items', ['config' => $configName]),
            'searchUrl' => $this->getEndpoint('datahub_rest_endpoints_element_get', ['config' => $configName]),
            'getElementByIdUrl' => $this->getEndpoint('datahub_rest_endpoints_element_get', ['config' => $configName]),
        ]);

        return $this->json([
            'name' => $configName,
            'configuration' => $configReader->toArray(),
            'userPermissions' => [
                'update' => $configuration->isAllowed('update'),
                'delete' => $configuration->isAllowed('delete'),
            ],
            'modificationDate' => $configuration->getModificationDate(),
        ]);
    }

    /**
     * @throws \Exception
     */
    #[Route('/label-list', name: 'label_list', methods: ['GET'])]
    public function labelListAction(
        DataHubConfigurationRepository $configRepository,
        IndexManager $indexManager,
        LabelExtractorInterface $labelExtractor,
        Request $request
    ): JsonResponse {
        $this->checkPermission(BaseConfigController::CONFIG_NAME);

        $configName = $request->get('name');
        $configuration = $configRepository->findOneByName($configName);

        if (!$configuration instanceof Configuration) {
            return new JsonResponse(['error' => sprintf('No DataHub configuration found for name "%s".', $configName)]);
        }

        $configReader = new ConfigReader($configuration->getConfiguration());
        $listing = new \Pimcore\Model\Asset\Listing();
        $result = [];
        foreach ($listing->getItems(0, 1000) as $asset) {
            $result[] = $this->assetProvider->getIndexData($asset, $configReader);
        }

        $labels = [];
        foreach ($result as $item) {
            foreach ($item as $dataKey => $dataList) {
                foreach (array_keys($dataList) as $subDataKey) {
                    $labels[] = $dataKey.'.'.$subDataKey;
                }
            }
        }

        return $this->json(['success' => true, 'labelList' => $labels]);
    }

    #[Route('/save', name: 'save', methods: ['POST'])]
    public function saveAction(
        DataHubConfigurationRepository $configRepository,
        EventDispatcherInterface $eventDispatcher,
        Request $request
    ): JsonResponse {
        $this->checkPermission(BaseConfigController::CONFIG_NAME);

        try {
            $data = $request->get('data');
            $modificationDate = $request->get('modificationDate', 0);
            $newConfigReader = new ConfigReader(json_decode((string) $data, true, 512, \JSON_THROW_ON_ERROR));

            $name = $newConfigReader->getName();
            $configuration = $configRepository->findOneByName($name);

            if (!$configuration instanceof Configuration) {
                return new JsonResponse(['error' => sprintf('No DataHub configuration found for name "%s".', $name)]);
            }

            $reader = new ConfigReader($configuration->getConfiguration());
            $savedModificationDate = $reader->getModificationDate();
            if ($modificationDate < $savedModificationDate) {
                // throw new \Exception('The configuration has been changed during editing.');
            }

            // ToDo Fix modifcationDate
            //            if ($modificationDate < $savedModificationDate) {
            //                throw new RuntimeException('The configuration was modified during editing, please reload the configuration and make your changes again.');
            //            }

            $oldConfig = $reader->toArray();
            $newConfig = $newConfigReader->toArray();
            $newConfig['general']['modificationDate'] = time();

            $getModifiedConfigurationEvent = new GetModifiedConfigurationEvent($newConfig, $oldConfig);

            $eventDispatcher->dispatch($getModifiedConfigurationEvent, SimpleRESTAdapterEvents::CONFIGURATION_PRE_SAVE);

            if ($configuration->isAllowed('read') && $configuration->isAllowed('update')) {
                $configuration->setConfiguration($newConfig);
                $configuration->save();
            }

            $configurationEvent = new ConfigurationEvent($newConfig, $oldConfig);
            $eventDispatcher->dispatch($configurationEvent, SimpleRESTAdapterEvents::CONFIGURATION_POST_SAVE);

            return $this->json(['success' => true, 'modificationDate' => $configuration->getModificationDate()]);
        } catch (\Exception $exception) {
            return $this->json(['success' => false, 'message' => $exception->getMessage()]);
        }
    }

    #[Route('/thumbnails', name: 'thumbnails', methods: ['GET'])]
    public function thumbnailsAction(): JsonResponse
    {
        $this->checkPermission('thumbnails');

        $listing = new Listing();
        $thumbnails = array_map(
            static fn ($config): array => ['name' => $config->getName()],
            $listing->load()
        );

        return $this->json(['data' => $thumbnails]);
    }

    /**
     * @param array<string, string> $parameters
     */
    private function getEndpoint(string $route, array $parameters = []): string
    {
        return $this->generateUrl($route, $parameters, UrlGeneratorInterface::ABSOLUTE_URL);
    }
}
