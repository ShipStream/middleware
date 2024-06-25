<?php

/**
 * Plugin Exception model
 */
class Plugin_Exception extends Exception
{
    const EDI_ENTITY_TYPE_TRANSACTION = 'transaction';
    const EDI_ENTITY_TYPE_DOCUMENT = 'document';
    const EDI_ENTITY_TYPE_MESSAGE = 'message';

    protected ?string $_subjectType = NULL;
    protected ?string $_subjectName = NULL;
    protected ?string $_ediEntityType = NULL;
    protected ?int $_ediEntityId = NULL;
    protected bool $_skipAutoRetry = FALSE;
    private ?string $_signatureData = NULL;

    /**
     * Construct the exception. Note: The message is NOT binary safe.
     * @link https://php.net/manual/en/exception.construct.php
     * @param string $message [optional] The Exception message to throw.
     * @param int $code [optional] The Exception code.
     * @param null|Throwable $previous [optional] The previous throwable used for the exception chaining.
     * @param null|string $subjectType [optional] The subject type
     * @param null|string $subjectName [optional] The subject name
     */
    public function __construct($message = '', $code = 0, Throwable $previous = NULL, $subjectType = NULL, $subjectName = NULL)
    {
        $this->_subjectType = $subjectType;
        $this->_subjectName = $subjectName;

        parent::__construct($message, $code, $previous);
    }

    /**
     * @param string $type
     * @param null|string $name
     * @return $this
     */
    public function setSubject($type, $name = NULL)
    {
        $this->_subjectType = trim($type);
        $this->_subjectName = trim($name);

        return $this;
    }

    /**
     * @return string|null
     */
    public function getSubjectType()
    {
        return $this->_subjectType;
    }

    /**
     * @return string|null
     */
    public function getSubjectName()
    {
        return $this->_subjectName;
    }

    /**
     * @param string $type
     * @param int $id
     * @return $this
     */
    public function setEdiEntity(string $type, int $id)
    {
        $this->_ediEntityType = $type;
        $this->_ediEntityId = $id;
        return $this;
    }

    /**
     * @return string|null
     */
    public function getEdiEntityType(): ?string
    {
        return $this->_ediEntityType;
    }

    /**
     * @return int|null
     */
    public function getEdiEntityId(): ?int
    {
        return $this->_ediEntityId;
    }

    /**
     * Set true to disable automatic retries (manual retries are still allowed)
     *
     * @param bool $value
     * @return $this
     */
    public function setSkipAutoRetry(bool $value)
    {
        $this->_skipAutoRetry = $value;

        return $this;
    }

    /**
     * @return bool
     */
    public function getSkipAutoRetry(): bool
    {
        return $this->_skipAutoRetry;
    }

    public function setSignatureData(string $value): void
    {
        $this->_signatureData = $value;
    }

    public function getSignatureData(): ?string
    {
        return $this->_signatureData;
    }
}
