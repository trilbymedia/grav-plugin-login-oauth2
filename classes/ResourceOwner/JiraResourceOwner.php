<?php
/**
 * @author      Wizaplace DevTeam <dev@wizaplace.com>
 * @copyright   Copyright (c) Wizaplace
 * @license     MIT
 */

namespace Grav\Plugin\Login\OAuth2\ResourceOwner;

use League\OAuth2\Client\Provider\ResourceOwnerInterface;

class JiraResourceOwner implements ResourceOwnerInterface, \ArrayAccess
{
    /** @var array */
    private $data = [];

    public function __construct(array $data)
    {
        $this->data = $data;
    }

    /**
     * @return string
     * @throws \Exception
     */
    public function getId()
    {
        $id =  $this->offsetGet('id');

        if (is_null($id)) {
            throw new \Exception("[Jira Resource Owner] Unable to get id.");
        }

        return $id;
    }

    /**
     * @return string
     */
    public function getName()
    {
        return $this->offsetGet('name');
    }

    /**
     * @return string
     */
    public function getEmail()
    {
        return $this->offsetGet('email');
    }

    /**
     * @return string
     */
    public function getAvatarUrl()
    {
        return $this->offsetGet('avatarUrl');
    }

    /**
     * @return array
     */
    public function toArray()
    {
        return $this->data;
    }

    /**
     * @param string $offset
     *
     * @return bool
     */
    public function offsetExists($offset)
    {
        return array_key_exists($offset, $this->data);
    }

    /**
     * @param string $offset
     *
     * @return mixed
     */
    public function offsetGet($offset)
    {
        return $this->offsetExists($offset) ? $this->data[$offset] : null;
    }

    /**
     * @param mixed $offset
     * @param mixed $value
     *
     * @return JiraResourceOwner
     */
    public function offsetSet($offset, $value)
    {
        if (is_null($offset)) {
            $this->data[] = $value;
        } else {
            $this->data[$offset] = $value;
        }

        return $this;
    }

    /**
     * @param mixed $offset
     *
     * @return JiraResourceOwner
     */
    public function offsetUnset($offset)
    {
        unset($this->data[$offset]);

        return $this;
    }
}
