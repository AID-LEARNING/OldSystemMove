<?php

namespace SenseiTarzan\OldSystemMove\Listener;

use pocketmine\event\EventPriority;
use pocketmine\event\Listener;
use pocketmine\event\server\DataPacketDecodeEvent;
use pocketmine\event\server\DataPacketReceiveEvent;
use pocketmine\event\server\DataPacketSendEvent;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\handler\InGamePacketHandler;
use pocketmine\network\mcpe\NetworkSession;
use pocketmine\network\mcpe\protocol\MovePlayerPacket;
use pocketmine\network\mcpe\protocol\PlayerActionPacket;
use pocketmine\network\mcpe\protocol\PlayerAuthInputPacket;
use pocketmine\network\mcpe\protocol\StartGamePacket;
use pocketmine\network\mcpe\protocol\types\PlayerAction;
use pocketmine\network\mcpe\protocol\types\PlayerMovementSettings;
use pocketmine\network\mcpe\protocol\types\PlayerMovementType;
use pocketmine\network\PacketHandlingException;
use pocketmine\timings\Timings;
use SenseiTarzan\ExtraEvent\Class\EventAttribute;

class PacketListener implements Listener
{

    #[EventAttribute(EventPriority::MONITOR)]
    public function onSend(DataPacketSendEvent $event)
    {
        $packets = $event->getPackets();
        foreach ($packets as $packet) {
            if ($packet instanceof StartGamePacket) {
                $packet->playerMovementSettings = new PlayerMovementSettings(PlayerMovementType::LEGACY, 0, false);
            }
        }
    }

    #[EventAttribute(EventPriority::LOWEST)]
    public function onDataReceiveRaw(DataPacketDecodeEvent $event)
    {
        if ($event->getPacketId() === PlayerAuthInputPacket::NETWORK_ID) {
            $event->cancel();
        }
    }

    #[EventAttribute(EventPriority::LOWEST)]
    public function onDataReceive(DataPacketReceiveEvent $event)
    {

        $packet = $event->getPacket();
        $origin = $event->getOrigin();
        if ($packet instanceof MovePlayerPacket) {
            $event->cancel();
            $handlerTimings = Timings::getHandleDataPacketTimings($packet);
            $handlerTimings->startTiming();
            try{
                if($this->handleMovePlayer($origin, $packet)){
                    $origin->getLogger()->debug("Unhandled " . $packet->getName());
                }
            }finally{
                $handlerTimings->stopTiming();
            }
        }
        if ($packet instanceof PlayerActionPacket) {
            $event->cancel();
            $handlerTimings = Timings::getHandleDataPacketTimings($packet);
            $handlerTimings->startTiming();
            try{
                if($this->handlePlayerAction($origin, $packet)){
                    $origin->getLogger()->debug("Unhandled " . $packet->getName());
                }
            }finally{
                $handlerTimings->stopTiming();
            }
        }
    }

    private function handleMovePlayer(NetworkSession $session, MovePlayerPacket $packet): bool
    {

        $player = $session->getPlayer();
        if ($player === null) {
            return false;
        }
        $handler = $session->getHandler();
        if (!$handler instanceof InGamePacketHandler) {
            return false;
        }
        $rawPos = $packet->position;
        foreach ([$rawPos->x, $rawPos->y, $rawPos->z, $packet->yaw, $packet->headYaw, $packet->pitch] as $float) {
            if (is_infinite($float) || is_nan($float)) {
                $session->getLogger()->debug("Invalid movement received, contains NAN/INF components");
                return false;
            }
        }
        $rawYaw = $packet->yaw;
        $rawPitch = $packet->pitch;

        $yaw = fmod($rawYaw, 360);
        $pitch = fmod($rawPitch, 360);
        if ($yaw < 0) {
            $yaw += 360;
        }

        $player->setRotation($yaw, $pitch);

        $curPos = $player->getLocation();
        $newPos = $packet->position->round(4)->subtract(0, 1.62, 0);

        if ($handler->forceMoveSync and $newPos->distanceSquared($curPos) > 1) {  //Tolerate up to 1 block to avoid problems with client-sided physics when spawning in blocks
            $session->getLogger()->debug("Got outdated pre-teleport movement, received " . $newPos . ", expected " . $curPos);
            //Still getting movements from before teleport, ignore them
            return false;
        }

        // Once we get a movement within a reasonable distance, treat it as a teleport ACK and remove position lock
        $handler->forceMoveSync = false;

        $player->handleMovement($newPos);

        return true;
    }

    public function handlePlayerAction(NetworkSession $session, PlayerActionPacket $packet): bool
    {
        $player = $session->getPlayer();
        if ($player === null) {
            return false;
        }
        $inGameHandler = $session->getHandler();
        if (!$inGameHandler instanceof InGamePacketHandler) {
            return false;
        }
        $pos = new Vector3($packet->blockPosition->getX(), $packet->blockPosition->getY(), $packet->blockPosition->getZ());

        switch ($packet->action) {
            case PlayerAction::START_BREAK:
                $face = $packet->face;
                self::validateFacing($face);
                if (!$player->attackBlock($pos, $face)) {
                    $this->onFailedBlockAction($session, $pos, $face);
                }

                break;
            case PlayerAction::CRACK_BREAK:
                $face = $packet->face;
                self::validateFacing($face);
                $player->continueBreakBlock($pos, $face);
                break;

            case PlayerAction::ABORT_BREAK:
            case PlayerAction::STOP_BREAK:
                $player->stopBreakBlock($pos);
                break;
            case PlayerAction::START_SLEEPING:
                //unused
                break;
            case PlayerAction::STOP_SLEEPING:
                $player->stopSleep();
                break;
            case PlayerAction::JUMP:
                $player->jump();
                return true;
            case PlayerAction::START_SPRINT:
                if (!$player->toggleSprint(true)) {
                    $player->sendData([$player]);
                }
                return true;
            case PlayerAction::STOP_SPRINT:
                if (!$player->toggleSprint(false)) {
                    $player->sendData([$player]);
                }
                return true;
            case PlayerAction::START_SNEAK:
                if (!$player->toggleSneak(true)) {
                    $player->sendData([$player]);
                }
                return true;
            case PlayerAction::STOP_SNEAK:
                if (!$player->toggleSneak(false)) {
                    $player->sendData([$player]);
                }
                return true;
            case PlayerAction::START_GLIDE:
                if ($player->toggleGlide(true)) {
                    $player->sendData([$player]);
                }
                break;
            case PlayerAction::STOP_GLIDE:
                if ($player->toggleGlide(false)) {
                    $player->sendData([$player]);
                }
                break;
            case PlayerAction::START_SWIMMING:
                if ($player->toggleSwim(true)) {
                    $player->sendData([$player]);
                }
                break; //TODO
            case PlayerAction::STOP_SWIMMING:
                if ($player->toggleSwim(false)) {
                    $player->sendData([$player]);
                }
                break;
            case PlayerAction::INTERACT_BLOCK: //TODO: ignored (for now)
                break;
            case PlayerAction::CREATIVE_PLAYER_DESTROY_BLOCK:
                //TODO: do we need to handle this?
                break;
            case PlayerAction::START_ITEM_USE_ON:
            case PlayerAction::STOP_ITEM_USE_ON:
                //TODO: this has no obvious use and seems only used for analytics in vanilla - ignore it
                break;
            default:
                $session->getLogger()->debug("Unhandled/unknown player action type " . $packet->action);
                return false;
        }

        $player->setUsingItem(false);

        return true;
    }


    /**
     * @throws PacketHandlingException
     */
    private static function validateFacing(int $facing): void
    {
        if (!in_array($facing, Facing::ALL, true)) {
            throw new PacketHandlingException("Invalid facing value $facing");
        }
    }


    /**
     * Internal function used to execute rollbacks when an action fails on a block.
     */
    private function onFailedBlockAction(NetworkSession $session, Vector3 $blockPos, ?int $face) : void{
        $player = $session->getPlayer();
        if($player === null){
            return;
        }
        if($blockPos->distanceSquared($player->getLocation()) < 10000){
            $blocks = $blockPos->sidesArray();
            if($face !== null){
                $sidePos = $blockPos->getSide($face);
                array_push($blocks, ...$sidePos->sidesArray()); //getAllSides() on each of these will include $blockPos and $sidePos because they are next to each other
            }else{
                $blocks[] = $blockPos;
            }
            foreach($player->getWorld()->createBlockUpdatePackets($blocks) as $packet){
                $session->sendDataPacket($packet);
            }
        }
    }


}