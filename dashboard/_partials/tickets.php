<?php
declare(strict_types=1);

/** @var int|null $currentBotId */

// Liste der Commands basierend auf deinem Verzeichnis: 
// /mnt/4TBNvme/testbot/Discord-Bot/src/commands/tickets
$ticketCommands = [
    ['name' => 'setup', 'desc' => 'Initialisiert das Ticket-System auf dem Server'],
    ['name' => 'add', 'desc' => 'Fügt einen weiteren Nutzer zum Ticket-Kanal hinzu'],
    ['name' => 'remove', 'desc' => 'Entfernt einen Nutzer aus dem Ticket-Kanal'],
    ['name' => 'close', 'desc' => 'Schließt das Ticket und archiviert den Verlauf'],
    ['name' => 'transcript', 'desc' => 'Erstellt eine HTML/Text Kopie des Ticket-Verlaufs'],
    ['name' => 'rename', 'desc' => 'Ändert den Namen des Ticket-Kanals'],
];
?>
<div class="px-4 sm:px-6 lg:px-8 py-8 w-full max-w-9xl mx-auto">
    <div class="sm:flex sm:justify-between sm:items-center mb-8">
        <div class="mb-4 sm:mb-0">
            <h1 class="text-2xl md:text-3xl text-gray-800 dark:text-gray-100 font-bold">Tickets Modul ✨</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Verwalte die Commands für Bot #<?= $currentBotId ?? '?' ?></p>
        </div>
    </div>

    <div class="bg-white dark:bg-gray-800 shadow-lg rounded-2xl border border-gray-100 dark:border-gray-700/60 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="table-auto w-full">
                <thead class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400 bg-gray-50 dark:bg-gray-900/20 border-b border-gray-100 dark:border-gray-700/60">
                    <tr>
                        <th class="px-4 py-3 first:pl-5 last:pr-5">
                            <div class="font-semibold text-left">Command</div>
                        </th>
                        <th class="px-4 py-3 first:pl-5 last:pr-5">
                            <div class="font-semibold text-left">Beschreibung</div>
                        </th>
                        <th class="px-4 py-3 first:pl-5 last:pr-5">
                            <div class="font-semibold text-center">Status</div>
                        </th>
                    </tr>
                </thead>
                <tbody class="text-sm divide-y divide-gray-100 dark:divide-gray-700/60">
                    <?php foreach ($ticketCommands as $cmd): ?>
                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/10 transition-colors">
                        <td class="px-4 py-3 first:pl-5 last:pr-5 whitespace-nowrap">
                            <div class="font-medium text-violet-500">/<?= htmlspecialchars($cmd['name']) ?></div>
                        </td>
                        <td class="px-4 py-3 first:pl-5 last:pr-5">
                            <div class="text-gray-600 dark:text-gray-400"><?= htmlspecialchars($cmd['desc']) ?></div>
                        </td>
                        <td class="px-4 py-3 first:pl-5 last:pr-5 whitespace-nowrap">
                            <div class="flex items-center justify-center" x-data="{ enabled: true }">
                                <div class="relative inline-block w-10 h-6 transition duration-200 ease-in-out">
                                    <input 
                                        type="checkbox" 
                                        id="cmd_<?= $cmd['name'] ?>" 
                                        class="peer appearance-none w-10 h-6 rounded-full bg-gray-300 dark:bg-gray-700 checked:bg-violet-500 cursor-pointer transition-colors duration-200" 
                                        x-model="enabled"
                                    />
                                    <label 
                                        for="cmd_<?= $cmd['name'] ?>" 
                                        class="absolute top-1 left-1 w-4 h-4 bg-white rounded-full transition-transform duration-200 peer-checked:translate-x-4 cursor-pointer"
                                    ></label>
                                </div>
                                <span class="ml-3 text-xs font-medium w-16" :class="enabled ? 'text-violet-500' : 'text-gray-400'" x-text="enabled ? 'Aktiv' : 'Inaktiv'"></span>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-8 flex justify-end">
        <button class="px-4 py-2 bg-violet-500 hover:bg-violet-600 text-white text-sm font-medium rounded-lg transition-colors shadow-sm shadow-violet-500/20">
            Konfiguration speichern
        </button>
    </div>
</div>