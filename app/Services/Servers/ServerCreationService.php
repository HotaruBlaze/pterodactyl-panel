<?php

namespace Pterodactyl\Services\Servers;

use Ramsey\Uuid\Uuid;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Pterodactyl\Models\Node;
use Pterodactyl\Models\User;
use Pterodactyl\Models\Server;
use Illuminate\Support\Collection;
use Pterodactyl\Models\Allocation;
use Illuminate\Database\ConnectionInterface;
use Pterodactyl\Models\Objects\DeploymentObject;
use Pterodactyl\Repositories\Eloquent\EggRepository;
use Pterodactyl\Repositories\Eloquent\ServerRepository;
use Pterodactyl\Repositories\Wings\DaemonServerRepository;
use Pterodactyl\Repositories\Eloquent\AllocationRepository;
use Pterodactyl\Services\Deployment\FindViableNodesService;
use Pterodactyl\Repositories\Eloquent\ServerVariableRepository;
use Pterodactyl\Services\Deployment\AllocationSelectionService;

class ServerCreationService
{
    /**
     * @var \Pterodactyl\Repositories\Eloquent\AllocationRepository
     */
    private $allocationRepository;

    /**
     * @var \Pterodactyl\Services\Deployment\AllocationSelectionService
     */
    private $allocationSelectionService;

    /**
     * @var \Pterodactyl\Services\Servers\ServerConfigurationStructureService
     */
    private $configurationStructureService;

    /**
     * @var \Illuminate\Database\ConnectionInterface
     */
    private $connection;

    /**
     * @var \Pterodactyl\Services\Deployment\FindViableNodesService
     */
    private $findViableNodesService;

    /**
     * @var \Pterodactyl\Services\Servers\VariableValidatorService
     */
    private $validatorService;

    /**
     * @var \Pterodactyl\Repositories\Eloquent\EggRepository
     */
    private $eggRepository;

    /**
     * @var \Pterodactyl\Repositories\Eloquent\ServerRepository
     */
    private $repository;

    /**
     * @var \Pterodactyl\Repositories\Eloquent\ServerVariableRepository
     */
    private $serverVariableRepository;

    /**
     * @var \Pterodactyl\Repositories\Wings\DaemonServerRepository
     */
    private $daemonServerRepository;

    /**
     * CreationService constructor.
     *
     * @param \Pterodactyl\Repositories\Eloquent\AllocationRepository $allocationRepository
     * @param \Pterodactyl\Services\Deployment\AllocationSelectionService $allocationSelectionService
     * @param \Illuminate\Database\ConnectionInterface $connection
     * @param \Pterodactyl\Repositories\Wings\DaemonServerRepository $daemonServerRepository
     * @param \Pterodactyl\Repositories\Eloquent\EggRepository $eggRepository
     * @param \Pterodactyl\Services\Deployment\FindViableNodesService $findViableNodesService
     * @param \Pterodactyl\Services\Servers\ServerConfigurationStructureService $configurationStructureService
     * @param \Pterodactyl\Repositories\Eloquent\ServerRepository $repository
     * @param \Pterodactyl\Repositories\Eloquent\ServerVariableRepository $serverVariableRepository
     * @param \Pterodactyl\Services\Servers\VariableValidatorService $validatorService
     */
    public function __construct(
        AllocationRepository $allocationRepository,
        AllocationSelectionService $allocationSelectionService,
        ConnectionInterface $connection,
        DaemonServerRepository $daemonServerRepository,
        EggRepository $eggRepository,
        FindViableNodesService $findViableNodesService,
        ServerConfigurationStructureService $configurationStructureService,
        ServerRepository $repository,
        ServerVariableRepository $serverVariableRepository,
        VariableValidatorService $validatorService
    ) {
        $this->allocationSelectionService = $allocationSelectionService;
        $this->allocationRepository = $allocationRepository;
        $this->configurationStructureService = $configurationStructureService;
        $this->connection = $connection;
        $this->findViableNodesService = $findViableNodesService;
        $this->validatorService = $validatorService;
        $this->eggRepository = $eggRepository;
        $this->repository = $repository;
        $this->serverVariableRepository = $serverVariableRepository;
        $this->daemonServerRepository = $daemonServerRepository;
    }

    /**
     * Create a server on the Panel and trigger a request to the Daemon to begin the server
     * creation process. This function will attempt to set as many additional values
     * as possible given the input data. For example, if an allocation_id is passed with
     * no node_id the node_is will be picked from the allocation.
     *
     * @param array $data
     * @param \Pterodactyl\Models\Objects\DeploymentObject|null $deployment
     * @return \Pterodactyl\Models\Server
     *
     * @throws \Throwable
     * @throws \Pterodactyl\Exceptions\DisplayException
     * @throws \Illuminate\Validation\ValidationException
     * @throws \Pterodactyl\Exceptions\Model\DataValidationException
     * @throws \Pterodactyl\Exceptions\Repository\RecordNotFoundException
     * @throws \Pterodactyl\Exceptions\Service\Deployment\NoViableNodeException
     * @throws \Pterodactyl\Exceptions\Service\Deployment\NoViableAllocationException
     */
    public function handle(array $data, DeploymentObject $deployment = null): Server
    {
        $this->connection->beginTransaction();

        // If a deployment object has been passed we need to get the allocation
        // that the server should use, and assign the node from that allocation.
        if ($deployment instanceof DeploymentObject) {
            $allocation = $this->configureDeployment($data, $deployment);
            $data['allocation_id'] = $allocation->id;
            $data['node_id'] = $allocation->node_id;
        }

        // Auto-configure the node based on the selected allocation
        // if no node was defined.
        if (is_null(Arr::get($data, 'node_id'))) {
            $data['node_id'] = $this->getNodeFromAllocation($data['allocation_id']);
        }

        if (is_null(Arr::get($data, 'nest_id'))) {
            /** @var \Pterodactyl\Models\Egg $egg */
            $egg = $this->eggRepository->setColumns(['id', 'nest_id'])->find(Arr::get($data, 'egg_id'));
            $data['nest_id'] = $egg->nest_id;
        }

        $eggVariableData = $this->validatorService
            ->setUserLevel(User::USER_LEVEL_ADMIN)
            ->handle(Arr::get($data, 'egg_id'), Arr::get($data, 'environment', []));

        // Create the server and assign any additional allocations to it.
        $server = $this->createModel($data);
        $this->storeAssignedAllocations($server, $data);
        $this->storeEggVariables($server, $eggVariableData);

        $structure = $this->configurationStructureService->handle($server);

        $this->connection->transaction(function () use ($server, $structure) {
            $this->daemonServerRepository->setServer($server)->create($structure);
        });

        return $server;
    }

    /**
     * Gets an allocation to use for automatic deployment.
     *
     * @param array $data
     * @param \Pterodactyl\Models\Objects\DeploymentObject $deployment
     *
     * @return \Pterodactyl\Models\Allocation
     * @throws \Pterodactyl\Exceptions\DisplayException
     * @throws \Pterodactyl\Exceptions\Service\Deployment\NoViableAllocationException
     * @throws \Pterodactyl\Exceptions\Service\Deployment\NoViableNodeException
     */
    private function configureDeployment(array $data, DeploymentObject $deployment): Allocation
    {
        $nodes = $this->findViableNodesService->setLocations($deployment->getLocations())
            ->setDisk(Arr::get($data, 'disk'))
            ->setMemory(Arr::get($data, 'memory'))
            ->handle();

        return $this->allocationSelectionService->setDedicated($deployment->isDedicated())
            ->setNodes($nodes)
            ->setPorts($deployment->getPorts())
            ->handle();
    }

    /**
     * Store the server in the database and return the model.
     *
     * @param array $data
     * @return \Pterodactyl\Models\Server
     *
     * @throws \Pterodactyl\Exceptions\Model\DataValidationException
     */
    private function createModel(array $data): Server
    {
        $uuid = $this->generateUniqueUuidCombo();

        /** @var \Pterodactyl\Models\Server $model */
        $model = $this->repository->create([
            'external_id' => Arr::get($data, 'external_id'),
            'uuid' => $uuid,
            'uuidShort' => substr($uuid, 0, 8),
            'node_id' => Arr::get($data, 'node_id'),
            'name' => Arr::get($data, 'name'),
            'description' => Arr::get($data, 'description') ?? '',
            'skip_scripts' => Arr::get($data, 'skip_scripts') ?? isset($data['skip_scripts']),
            'suspended' => false,
            'owner_id' => Arr::get($data, 'owner_id'),
            'memory' => Arr::get($data, 'memory'),
            'swap' => Arr::get($data, 'swap'),
            'disk' => Arr::get($data, 'disk'),
            'io' => Arr::get($data, 'io'),
            'cpu' => Arr::get($data, 'cpu'),
            'oom_disabled' => Arr::get($data, 'oom_disabled', true),
            'allocation_id' => Arr::get($data, 'allocation_id'),
            'nest_id' => Arr::get($data, 'nest_id'),
            'egg_id' => Arr::get($data, 'egg_id'),
            'pack_id' => empty($data['pack_id']) ? null : $data['pack_id'],
            'startup' => Arr::get($data, 'startup'),
            'daemonSecret' => Str::random(Node::DAEMON_SECRET_LENGTH),
            'image' => Arr::get($data, 'image'),
            'database_limit' => Arr::get($data, 'database_limit'),
            'allocation_limit' => Arr::get($data, 'allocation_limit'),
        ]);

        return $model;
    }

    /**
     * Configure the allocations assigned to this server.
     *
     * @param \Pterodactyl\Models\Server $server
     * @param array $data
     */
    private function storeAssignedAllocations(Server $server, array $data)
    {
        $records = [$data['allocation_id']];
        if (isset($data['allocation_additional']) && is_array($data['allocation_additional'])) {
            $records = array_merge($records, $data['allocation_additional']);
        }

        $this->allocationRepository->assignAllocationsToServer($server->id, $records);
    }

    /**
     * Process environment variables passed for this server and store them in the database.
     *
     * @param \Pterodactyl\Models\Server $server
     * @param \Illuminate\Support\Collection $variables
     */
    private function storeEggVariables(Server $server, Collection $variables)
    {
        $records = $variables->map(function ($result) use ($server) {
            return [
                'server_id' => $server->id,
                'variable_id' => $result->id,
                'variable_value' => $result->value,
            ];
        })->toArray();

        if (! empty($records)) {
            $this->serverVariableRepository->insert($records);
        }
    }

    /**
     * Get the node that an allocation belongs to.
     *
     * @param int $id
     * @return int
     *
     * @throws \Pterodactyl\Exceptions\Repository\RecordNotFoundException
     */
    private function getNodeFromAllocation(int $id): int
    {
        /** @var \Pterodactyl\Models\Allocation $allocation */
        $allocation = $this->allocationRepository->setColumns(['id', 'node_id'])->find($id);

        return $allocation->node_id;
    }

    /** @noinspection PhpDocMissingThrowsInspection */

    /**
     * Create a unique UUID and UUID-Short combo for a server.
     *
     * @return string
     */
    private function generateUniqueUuidCombo(): string
    {
        /** @noinspection PhpUnhandledExceptionInspection */
        $uuid = Uuid::uuid4()->toString();

        if (! $this->repository->isUniqueUuidCombo($uuid, substr($uuid, 0, 8))) {
            return $this->generateUniqueUuidCombo();
        }

        return $uuid;
    }
}
