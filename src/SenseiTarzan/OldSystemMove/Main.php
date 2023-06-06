<?php

namespace SenseiTarzan\OldSystemMove;

use pocketmine\plugin\PluginBase;
use SenseiTarzan\OldSystemMove\Listener\PacketListener;
use SenseiTarzan\ExtraEvent\Component\EventLoader;

class Main extends PluginBase
{
    protected function onEnable(): void
    {
        EventLoader::loadEventWithClass($this, PacketListener::class);

    }
}