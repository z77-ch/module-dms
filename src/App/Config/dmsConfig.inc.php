<?php
namespace Z77\Module\Dms\App;

use Z77\Core\Config\AuthRole;

return [
    'defaultGroup'  => 'media',
    'groupDefaults' => [
        'media' => 'output',
    ],
    'defaultAction' => 'serve',
    'moduleRole'    => AuthRole::GUEST,

    // Byte delivery is never page-cached (FileResponse is not an HtmlResponse).
    'cache' => [
        'enabled' => false,
    ],

    // Optional remapping of a module's write target (ADR-021 rule 4): module key →
    // partition key, consumed by DocumentService::forModule(). No entry = the module
    // writes into the partition of its OWN key (get-or-create). The module key itself
    // stays a code constant (S2) — this only redirects WHERE that module writes, e.g.
    // 'moduleFolders' => ['financial' => 'archive'].
    'moduleFolders' => [],

    // Reserved route (ADR-017 §8 / R3+R4c / ADR-020): the structural, mode-independent
    // delivery URL. Highest routing precedence (before alias/nav/convention AND before
    // the Fetch short-circuit — /media is reached via <img src> = Fetch mode). The
    // trailing path becomes content slugs: /media/{root-slug}/{folder-slug…}/{file} —
    // the first segment is the partition root's slug (a real folder, ADR-020).
    'reservedRoutes' => [
        '/media' => [
            'module'     => 'dms',
            'group'      => 'media',
            'controller' => 'output',
            'action'     => 'serve',
        ],
    ],

    // The OutputController is GUEST-reachable; the per-document gate (deliveryMode +
    // AclService::canRead for protected/sealed) runs inside the action, not via roles.
    'controllers' => [
        'media' => [
            'OutputController' => [
                'defaultAction'  => 'serve',
                'controllerRole' => AuthRole::GUEST,
                'actions'        => [
                    'serveAction' => AuthRole::GUEST,
                ],
            ],
        ],
    ],
];
