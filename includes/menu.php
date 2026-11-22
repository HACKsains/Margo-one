<?php
/**
 * ==============================================================================
 * MARGO ONE — ILMUNA SSO · SIDEBAR GROUP & NAVIGATION CONFIGURATION
 * ==============================================================================
 * Struktur Accordion Menu 2-Grup:
 *   1. Menu Utama (Beranda, Aplikasi, Agenda, Profil & Keamanan)
 *   2. Kontrol Admin (Khusus role admin: Klien, Sesi, Akun, JWKS/Audit, Kelola Portal)
 * Eksklusif Iconify Solar Icons (solar:*).
 * ==============================================================================
 */

if (!defined('BASE_PATH')) {
    exit;
}

$margoSidebarGroups = [
    [
        'key'      => 'main',
        'title'    => 'Menu Utama',
        'icon'     => 'solar:layers-minimalistic-bold',
        'is_admin' => false,
        'items'    => [
            [
                'label' => 'Beranda Portal',
                'href'  => BASE_URL . '/gateway',
                'icon'  => 'solar:home-smile-bold',
                'route' => 'gateway',
            ],
            [
                'label' => 'Aplikasi Ekosistem',
                'href'  => BASE_URL . '/gateway#apps-container',
                'icon'  => 'solar:widget-5-bold',
                'route' => 'apps',
            ],
            [
                'label' => 'Agenda & Jadwal',
                'href'  => BASE_URL . '/agenda',
                'icon'  => 'solar:calendar-mark-bold',
                'route' => 'agenda',
            ],
            [
                'label' => 'Profil & Keamanan',
                'href'  => BASE_URL . '/profile',
                'icon'  => 'solar:user-circle-bold',
                'route' => 'profile',
            ],
        ],
    ],
];

if (function_exists('hasRole') && hasRole('admin')) {
    $margoSidebarGroups[] = [
        'key'      => 'admin',
        'title'    => 'Kontrol Admin',
        'icon'     => 'solar:shield-keyhole-bold',
        'is_admin' => true,
        'items'    => [
            [
                'label' => 'Aplikasi Klien & Kunci',
                'href'  => BASE_URL . '/dashboard/admin/clients',
                'icon'  => 'solar:key-minimalistic-square-2-bold',
                'route' => 'clients',
                'badge' => ['text' => 'SSO', 'variant' => 'teal'],
            ],
            [
                'label' => 'Sesi Aktif Global',
                'href'  => BASE_URL . '/dashboard/admin/sessions',
                'icon'  => 'solar:devices-bold',
                'route' => 'sessions',
            ],
            [
                'label' => 'Identitas & Multi-Role',
                'href'  => BASE_URL . '/dashboard/admin/users',
                'icon'  => 'solar:users-group-rounded-bold',
                'route' => 'users',
            ],
            [
                'label' => 'JWKS, SDK & Audit Log',
                'href'  => BASE_URL . '/dashboard/admin/audit-logs',
                'icon'  => 'solar:history-bold',
                'route' => 'jwks',
                'badge' => ['text' => 'RS256', 'variant' => 'gold'],
            ],
            [
                'label' => 'Kelola Portal & Agenda',
                'href'  => BASE_URL . '/dashboard/admin/portal',
                'icon'  => 'solar:calendar-add-bold',
                'route' => 'portal',
                'badge' => ['text' => 'CMS', 'variant' => 'gold'],
            ],
        ],
    ];
}
