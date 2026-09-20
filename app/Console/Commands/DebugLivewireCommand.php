<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Livewire\Drawer\Utils;
use Throwable;

/**
 * What Livewire actually sees when it resolves a component.
 *
 * ── WHY THIS EXISTS ──────────────────────────────────────────────────────
 *
 * "Public method [x] not found on component" is a message with a blind spot:
 * it names the method and never names the component. So a call landing on the
 * WRONG component and a method genuinely missing from the RIGHT one produce
 * the same sentence, and the two have nothing in common — one is a markup
 * problem, the other is a PHP one.
 *
 * This prints both halves: which class a component name resolves to, and the
 * exact list of methods Livewire will accept calls for. It uses the same
 * function that throws the exception, so there is no chance of checking
 * something adjacent and believing it.
 *
 * Temporary. Delete it once the question it was written for is answered.
 */
class DebugLivewireCommand extends Command
{
    protected $signature = 'dbelo:debug-livewire
        {names?* : Component names, e.g. collection-picker favorite-button}';

    protected $description = 'Show which class a Livewire component name resolves to, and its callable methods';

    public function handle(): int
    {
        $names = $this->argument('names') ?: ['collection-picker', 'favorite-button'];

        foreach ($names as $name) {
            $this->newLine();
            $this->line("  <options=bold>{$name}</>");

            try {
                $component = app('livewire')->new($name);
            } catch (Throwable $e) {
                $this->line('  <fg=red>could not build it</>  '.$e->getMessage());

                continue;
            }

            $this->line('  class    <fg=cyan>'.get_class($component).'</>');
            $this->line('  file     '.$this->fileOf($component));

            $methods = Utils::getPublicMethodsDefinedBySubClass($component);
            sort($methods);

            $this->line('  methods  '.(empty($methods) ? '<fg=red>NONE</>' : implode(', ', $methods)));
        }

        $this->newLine();
        $this->line('  <fg=gray>"methods" is the exact allow-list Livewire checks a wire:click against.</>');
        $this->newLine();

        return self::SUCCESS;
    }

    private function fileOf(object $component): string
    {
        try {
            $path = (new \ReflectionObject($component))->getFileName();

            return $path ? str_replace(base_path().'/', '', $path) : '(no file)';
        } catch (Throwable) {
            return '(unknown)';
        }
    }
}
