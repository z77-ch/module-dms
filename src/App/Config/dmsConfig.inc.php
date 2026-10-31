<?php
namespace Z77\Module\Dms\App;

use Z77\Core\Config\AuthRole;

return [
    // The Drive answers a `/media/…` path with its file for server-side readers (kernel LocalFiles, 2026-10-23: the mandator logos).
    'localFileResolvers' => [\Z77\Module\Dms\Files\MediaFileResolver::class],
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

    // Extra roles a PROJECT grants to a non-backend identity (a member), so a
    // role ACE can open a folder to SOME members instead of all `customer`s
    // (F1, z77-rudaz.ch 2026-10-30). FQCN of an invokable
    //   `__invoke(\Z77\Shared\Auth\AuthUser $user): list<string>`
    // — e.g. ['rudaz-p12-read'] for a member of project 12; that project's folder
    // carries the matching role ACE. Framework role names are dropped, a hook that
    // throws grants nothing ({@see \Z77\Module\Dms\Services\PrincipalRoles}).
    // Null = no extra roles — the DMS behaves exactly as before.
    'principalRolesHook' => null,

    // File types THIS installation accepts by extension although `finfo` cannot name
    // them (F4, z77-rudaz.ch 2026-10-31): point clouds, BIM, … A list of extensions or
    // ext => MIME, e.g. ['laz', 'las', 'e57', 'ifc' => 'application/x-step', 'xml'].
    // A listed extension wins over the sniff and becomes kind `file`: never inline, no
    // variants, no static public copy, always a download; stored MIME = the listed one or
    // application/octet-stream. Server-executable / page types (php, html, svg, js, …)
    // are ignored ({@see \Z77\Module\Dms\Images\UploadTypes}). Empty = as before.
    'extraUploadTypes' => [],

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
