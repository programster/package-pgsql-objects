<?php

use Programster\PgsqlObjects\AbstractRecord;
use Programster\PgsqlObjects\TableInterface;

class SubscriptionRecord extends AbstractRecord
{
    private ?string $m_subscriberEmail = null;
    private ?string $m_list = null;


    public function getTableHandler() : TableInterface
    {
        return SubscriptionTable::getInstance();
    }


    protected function getAccessorFunctions() : array
    {
        return [
            'subscriber_email' => function () : ?string { return $this->m_subscriberEmail; },
            'list'             => function () : ?string { return $this->m_list; },
        ];
    }


    protected function getSetFunctions() : array
    {
        return [
            'subscriber_email' => function (?string $x) : void { $this->m_subscriberEmail = $x; },
            'list'             => function (?string $x) : void { $this->m_list = $x; },
        ];
    }


    # Accessors
    public function getSubscriberEmail() : ?string { return $this->m_subscriberEmail; }
    public function getList() : ?string { return $this->m_list; }
}
