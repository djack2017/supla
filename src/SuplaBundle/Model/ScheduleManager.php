<?php
/*
 This program is free software; you can redistribute it and/or
 modify it under the terms of the GNU General Public License
 as published by the Free Software Foundation; either version 2
 of the License, or (at your option) any later version.

 This program is distributed in the hope that it will be useful,
 but WITHOUT ANY WARRANTY; without even the implied warranty of
 MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 GNU General Public License for more details.

 You should have received a copy of the GNU General Public License
 along with this program; if not, write to the Free Software
 Foundation, Inc., 59 Temple Place - Suite 330, Boston, MA  02111-1307, USA.
 */

namespace SuplaBundle\Model;

use Cocur\Slugify\Slugify;
use Doctrine\Bundle\DoctrineBundle\Registry;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use SuplaBundle\Entity\IODeviceChannel;
use SuplaBundle\Entity\Schedule;
use SuplaBundle\Entity\ScheduledExecution;
use SuplaBundle\Entity\User;

class ScheduleManager
{
    /** @var Registry */
    private $doctrine;
    /** @var EntityManagerInterface */
    private $entityManager;
    /** @var EntityRepository */
    private $scheduledExecutionsRepository;
    /** @var IODeviceManager */
    private $ioDeviceManager;

    public function __construct($doctrine, IODeviceManager $ioDeviceManager)
    {
        $this->doctrine = $doctrine;
        $this->entityManager = $doctrine->getManager();
        $this->scheduledExecutionsRepository = $doctrine->getRepository('SuplaBundle:ScheduledExecution');
        $this->ioDeviceManager = $ioDeviceManager;
    }

    /** @return IODeviceChannel[] */
    public function getSchedulableChannels(User $user)
    {
        $schedulableFunctions = $this->getFunctionsThatCanBeScheduled();
        $channels = $this->doctrine->getRepository('SuplaBundle:IODeviceChannel')->findBy(['user' => $user]);
        $schedulableChannels = array_filter($channels, function (IODeviceChannel $channel) use ($schedulableFunctions) {
            return in_array($channel->getFunction(), $schedulableFunctions);
        });
        $slugify = new Slugify(['separator' => ' ']);
        usort($schedulableChannels, function (IODeviceChannel $channelA, IODeviceChannel $channelB) use ($slugify) {
            if ($channelA->getFunction() == $channelB->getFunction()) {
                $captionA = $channelA->getCaption();
                $captionB = $channelB->getCaption();
                if (!$captionA) {
                    return 1;
                } else if (!$captionB) {
                    return -1;
                } else {
                    return strcmp($slugify->slugify($captionA), $slugify->slugify($captionB));
                }
            } else {
                $functionNameA = $this->ioDeviceManager->channelFunctionToString($channelA->getFunction());
                $functionNameB = $this->ioDeviceManager->channelFunctionToString($channelB->getFunction());
                return strcmp($slugify->slugify($functionNameA), $slugify->slugify($functionNameB));
            }
        });
        return $schedulableChannels;
    }

    private function getFunctionsThatCanBeScheduled()
    {
        return array_keys($this->ioDeviceManager->functionActionMap());
    }

    public function generateScheduledExecutions(Schedule $schedule, $until = '+5days')
    {
        $nextRunDates = $this->getNextRunDates($schedule, $until);
        foreach ($nextRunDates as $nextRunDate) {
            $this->entityManager->persist(new ScheduledExecution($schedule, $nextRunDate));
        }
        /** @var \DateTime $nextCalculationDate */
        $nextCalculationDate = clone end($nextRunDates);
        $nextCalculationDate->sub(new \DateInterval('P2D')); // the oldest scheduled execution minus 2 days
        $schedule->setNextCalculationDate($nextCalculationDate);
        $this->entityManager->persist($schedule);
        $this->entityManager->flush();
    }

    public function getNextRunDates(Schedule $schedule, $until = '+5days', $count = PHP_INT_MAX)
    {
        $userTimezone = new \DateTimeZone($schedule->getUser()->getTimezone());
        if ($schedule->getDateEnd()) {
            $schedule->getDateEnd()->setTimezone($userTimezone);
            $until = min($schedule->getDateEnd()->getTimestamp(), strtotime($until));
        }
        if ($schedule->getDateStart()->getTimestamp() < time()) {
            $schedule->getDateStart()->setTimestamp(time());
        }
        $dateStart = $schedule->getDateStart();
        $latestExecution = current($this->scheduledExecutionsRepository->findBy(['schedule' => $schedule], ['timestamp' => 'DESC'], 1));
        if ($latestExecution) {
            $dateStart = $latestExecution->getTimestamp();
        }
        $dateStart->setTimezone($userTimezone);
        return $schedule->getRunDatesUntil($until, $dateStart, $count);
    }

    public function findClosestExecutions(Schedule $schedule, $contextSize = 3)
    {
        $criteria = new \Doctrine\Common\Collections\Criteria();
        $now = $this->getNow();
        $criteria
            ->where($criteria->expr()->gte('timestamp', $now))
            ->andWhere($criteria->expr()->eq('schedule', $schedule))
            ->orderBy(['timestamp' => 'ASC'])
            ->setMaxResults($contextSize + 1);
        $inFuture = $this->scheduledExecutionsRepository->matching($criteria)->toArray();
        $criteria = new \Doctrine\Common\Collections\Criteria();
        $criteria
            ->where($criteria->expr()->lt('timestamp', $now))
            ->andWhere($criteria->expr()->eq('schedule', $schedule))
            ->orderBy(['timestamp' => 'DESC'])
            ->setMaxResults($contextSize);
        $inPast = $this->scheduledExecutionsRepository->matching($criteria)->toArray();
        return [
            'past' => array_reverse($inPast),
            'future' => $inFuture
        ];
    }

    public function disable(Schedule $schedule)
    {
        $schedule->setEnabled(false);
        $this->deleteScheduledExecutions($schedule);
        $schedule->setNextCalculationDate($this->getNow());
        $this->entityManager->persist($schedule);
        $this->entityManager->flush();
    }

    private function deleteScheduledExecutions(Schedule $schedule)
    {
        $this->entityManager->createQueryBuilder()
            ->delete('SuplaBundle:ScheduledExecution', 's')
            ->where('s.schedule = :schedule')
            ->setParameter('schedule', $schedule)
            ->getQuery()
            ->execute();
    }

    public function enable(Schedule $schedule)
    {
        $schedule->setEnabled(true);
        $this->generateScheduledExecutions($schedule);
    }

    public function delete(Schedule $schedule)
    {
        $this->deleteScheduledExecutions($schedule);
        $this->entityManager->remove($schedule);
        $this->entityManager->flush();
    }

    private function getNow()
    {
        return new \DateTime('now', new \DateTimeZone('UTC'));
    }
}