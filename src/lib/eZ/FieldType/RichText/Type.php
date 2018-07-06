<?php

/**
 * @copyright Copyright (C) eZ Systems AS. All rights reserved.
 * @license For full copyright and license information view LICENSE file distributed with this source code.
 */
declare(strict_types=1);
/**
 * File containing the Type class.
 *
 * @copyright Copyright (C) eZ Systems AS. All rights reserved.
 * @license For full copyright and license information view LICENSE file distributed with this source code.
 */

namespace EzSystems\EzPlatformRichText\eZ\FieldType\RichText;

use eZ\Publish\Core\FieldType\FieldType;
use eZ\Publish\Core\Base\Exceptions\InvalidArgumentException;
use eZ\Publish\Core\Base\Exceptions\InvalidArgumentType;
use eZ\Publish\Core\FieldType\ValidationError;
use eZ\Publish\SPI\FieldType\Value as SPIValue;
use eZ\Publish\SPI\Persistence\Content\FieldValue;
use eZ\Publish\Core\FieldType\Value as BaseValue;
use eZ\Publish\API\Repository\Values\Content\Relation;
use eZ\Publish\API\Repository\Values\ContentType\FieldDefinition;
use DOMDocument;
use EzSystems\EzPlatformRichText\eZ\RichText\ConverterDispatcher;
use EzSystems\EzPlatformRichText\eZ\RichText\CustomTagsValidator;
use EzSystems\EzPlatformRichText\eZ\RichText\InternalLinkValidator;
use EzSystems\EzPlatformRichText\eZ\RichText\Normalizer;
use EzSystems\EzPlatformRichText\eZ\RichText\Validator;
use EzSystems\EzPlatformRichText\eZ\RichText\ValidatorDispatcher;
use RuntimeException;

/**
 * RichText field type.
 */
class Type extends FieldType
{
    /**
     * @var \EzSystems\EzPlatformRichText\eZ\RichText\ValidatorDispatcher
     */
    protected $internalFormatValidator;

    /**
     * @var \EzSystems\EzPlatformRichText\eZ\RichText\ConverterDispatcher
     */
    protected $inputConverterDispatcher;

    /**
     * @var \EzSystems\EzPlatformRichText\eZ\RichText\Normalizer
     */
    protected $inputNormalizer;

    /**
     * @var null|\EzSystems\EzPlatformRichText\eZ\RichText\ValidatorDispatcher
     */
    protected $inputValidatorDispatcher;

    /**
     * @var null|\EzSystems\EzPlatformRichText\eZ\RichText\InternalLinkValidator
     */
    protected $internalLinkValidator;

    /**
     * @var null|\EzSystems\EzPlatformRichText\eZ\RichText\CustomTagsValidator
     */
    private $customTagsValidator;

    /**
     * @param \EzSystems\EzPlatformRichText\eZ\RichText\Validator $internalFormatValidator
     * @param \EzSystems\EzPlatformRichText\eZ\RichText\ConverterDispatcher $inputConverterDispatcher
     * @param \EzSystems\EzPlatformRichText\eZ\RichText\Normalizer|null $inputNormalizer
     * @param \EzSystems\EzPlatformRichText\eZ\RichText\ValidatorDispatcher|null $inputValidatorDispatcher
     * @param \EzSystems\EzPlatformRichText\eZ\RichText\InternalLinkValidator|null $internalLinkValidator
     * @param \EzSystems\EzPlatformRichText\eZ\RichText\CustomTagsValidator|null $customTagsValidator
     */
    public function __construct(
        Validator $internalFormatValidator,
        ConverterDispatcher $inputConverterDispatcher,
        Normalizer $inputNormalizer = null,
        ValidatorDispatcher $inputValidatorDispatcher = null,
        InternalLinkValidator $internalLinkValidator = null,
        CustomTagsValidator $customTagsValidator = null
    ) {
        $this->internalFormatValidator = $internalFormatValidator;
        $this->inputConverterDispatcher = $inputConverterDispatcher;
        $this->inputNormalizer = $inputNormalizer;
        $this->inputValidatorDispatcher = $inputValidatorDispatcher;
        $this->internalLinkValidator = $internalLinkValidator;
        $this->customTagsValidator = $customTagsValidator;
    }

    /**
     * Returns the field type identifier for this field type.
     *
     * @return string
     */
    public function getFieldTypeIdentifier()
    {
        return 'ezrichtext';
    }

    /**
     * Returns the name of the given field value.
     *
     * It will be used to generate content name and url alias if current field is designated
     * to be used in the content name/urlAlias pattern.
     *
     * @param \EzSystems\EzPlatformRichText\eZ\FieldType\RichText\Value $value
     *
     * @return string
     */
    public function getName(SPIValue $value)
    {
        $result = null;
        if ($section = $value->xml->documentElement->firstChild) {
            $textDom = $section->firstChild;

            if ($textDom && $textDom->hasChildNodes()) {
                $result = $textDom->firstChild->textContent;
            } elseif ($textDom) {
                $result = $textDom->textContent;
            }
        }

        if ($result === null) {
            $result = $value->xml->documentElement->textContent;
        }

        return trim(preg_replace(['/\n/', '/\s\s+/'], ' ', $result));
    }

    /**
     * Returns the fallback default value of field type when no such default
     * value is provided in the field definition in content types.
     *
     * @return \EzSystems\EzPlatformRichText\eZ\FieldType\RichText\Value
     */
    public function getEmptyValue()
    {
        return new Value();
    }

    /**
     * Returns if the given $value is considered empty by the field type.
     *
     * @param \EzSystems\EzPlatformRichText\eZ\FieldType\RichText\Value $value
     *
     * @return bool
     */
    public function isEmptyValue(SPIValue $value)
    {
        if ($value->xml === null) {
            return true;
        }

        return !$value->xml->documentElement->hasChildNodes();
    }

    /**
     * Inspects given $inputValue and potentially converts it into a dedicated value object.
     *
     * @param \EzSystems\EzPlatformRichText\eZ\FieldType\RichText\Value|\DOMDocument|string $inputValue
     *
     * @return \EzSystems\EzPlatformRichText\eZ\FieldType\RichText\Value the potentially converted and structurally plausible value
     */
    protected function createValueFromInput($inputValue)
    {
        if (is_string($inputValue)) {
            if (empty($inputValue)) {
                $inputValue = Value::EMPTY_VALUE;
            }

            if ($this->inputNormalizer !== null && $this->inputNormalizer->accept($inputValue)) {
                $inputValue = $this->inputNormalizer->normalize($inputValue);
            }

            $inputValue = $this->loadXMLString($inputValue);
        }

        if ($inputValue instanceof DOMDocument) {
            if ($this->inputValidatorDispatcher !== null) {
                $errors = $this->inputValidatorDispatcher->dispatch($inputValue);
                if (!empty($errors)) {
                    throw new InvalidArgumentException(
                        '$inputValue',
                        'Validation of XML content failed: ' . implode("\n", $errors)
                    );
                }
            }

            $inputValue = new Value(
                $this->inputConverterDispatcher->dispatch($inputValue)
            );
        }

        return $inputValue;
    }

    /**
     * Creates \DOMDocument from given $xmlString.
     *
     * @throws \eZ\Publish\API\Repository\Exceptions\InvalidArgumentException
     *
     * @param $xmlString
     *
     * @return \DOMDocument
     */
    protected function loadXMLString($xmlString)
    {
        $document = new DOMDocument();

        libxml_use_internal_errors(true);
        libxml_clear_errors();

        // Options:
        // - substitute entities
        // - disable network access
        // - relax parser limits for document size/complexity
        $success = $document->loadXML($xmlString, LIBXML_NOENT | LIBXML_NONET | LIBXML_PARSEHUGE);

        if (!$success) {
            $messages = [];

            foreach (libxml_get_errors() as $error) {
                $messages[] = trim($error->message);
            }

            throw new InvalidArgumentException(
                '$inputValue',
                'Could not create XML document: ' . implode("\n", $messages)
            );
        }

        return $document;
    }

    /**
     * Throws an exception if value structure is not of expected format.
     *
     * @throws \eZ\Publish\API\Repository\Exceptions\InvalidArgumentException if the value does not match the expected structure
     *
     * @param \EzSystems\EzPlatformRichText\eZ\FieldType\RichText\Value $value
     */
    protected function checkValueStructure(BaseValue $value)
    {
        if (!$value->xml instanceof DOMDocument) {
            throw new InvalidArgumentType(
                '$value->xml',
                'DOMDocument',
                $value
            );
        }
    }

    /**
     * Validates a field based on the validators in the field definition.
     *
     * This is a base implementation, returning an empty array() that indicates
     * that no validation errors occurred. Overwrite in derived types, if
     * validation is supported.
     *
     * @throws \eZ\Publish\API\Repository\Exceptions\InvalidArgumentException
     *
     * @param \eZ\Publish\API\Repository\Values\ContentType\FieldDefinition $fieldDefinition The field definition of the field
     * @param \EzSystems\EzPlatformRichText\eZ\FieldType\RichText\Value $value The field value for which an action is performed
     *
     * @return \eZ\Publish\SPI\FieldType\ValidationError[]
     */
    public function validate(FieldDefinition $fieldDefinition, SPIValue $value)
    {
        $validationErrors = [];

        $errors = $this->internalFormatValidator->validate($value->xml);

        if (!empty($errors)) {
            $validationErrors[] = new ValidationError(
                "Validation of XML content failed:\n" . implode("\n", $errors)
            );
        }

        if ($this->internalLinkValidator !== null) {
            $errors = $this->internalLinkValidator->validateDocument($value->xml);
            foreach ($errors as $error) {
                $validationErrors[] = new ValidationError($error);
            }
        }

        if ($this->customTagsValidator !== null) {
            $errors = $this->customTagsValidator->validateDocument($value->xml);
            foreach ($errors as $error) {
                $validationErrors[] = new ValidationError($error);
            }
        }

        return $validationErrors;
    }

    /**
     * Returns sortKey information.
     *
     * @see \eZ\Publish\Core\FieldType
     *
     * @param \EzSystems\EzPlatformRichText\eZ\FieldType\RichText\Value $value
     *
     * @return array|bool
     */
    protected function getSortInfo(BaseValue $value)
    {
        return false;
    }

    /**
     * Converts an $hash to the Value defined by the field type.
     * $hash accepts the following keys:
     *  - xml (XML string which complies internal format).
     *
     * @param mixed $hash
     *
     * @return \EzSystems\EzPlatformRichText\eZ\FieldType\RichText\Value $value
     */
    public function fromHash($hash)
    {
        if (!isset($hash['xml'])) {
            throw new RuntimeException("'xml' index is missing in hash.");
        }

        return $this->acceptValue($hash['xml']);
    }

    /**
     * Converts a $Value to a hash.
     *
     * @param \EzSystems\EzPlatformRichText\eZ\FieldType\RichText\Value $value
     *
     * @return mixed
     */
    public function toHash(SPIValue $value)
    {
        return ['xml' => (string)$value];
    }

    /**
     * Creates a new Value object from persistence data.
     * $fieldValue->data is supposed to be a string.
     *
     * @param \eZ\Publish\SPI\Persistence\Content\FieldValue $fieldValue
     *
     * @return Value
     */
    public function fromPersistenceValue(FieldValue $fieldValue)
    {
        return new Value($fieldValue->data);
    }

    /**
     * @param \EzSystems\EzPlatformRichText\eZ\FieldType\RichText\Value $value
     *
     * @return \eZ\Publish\SPI\Persistence\Content\FieldValue
     */
    public function toPersistenceValue(SPIValue $value)
    {
        return new FieldValue(
            [
                'data' => $value->xml->saveXML(),
                'externalData' => null,
                'sortKey' => $this->getSortInfo($value),
            ]
        );
    }

    /**
     * Returns whether the field type is searchable.
     *
     * @return bool
     */
    public function isSearchable()
    {
        return true;
    }

    /**
     * Returns relation data extracted from value.
     *
     * Not intended for \eZ\Publish\API\Repository\Values\Content\Relation::COMMON type relations,
     * there is a service API for handling those.
     *
     * @param \eZ\Publish\SPI\FieldType\Value $value
     *
     * @return array hash with relation type as key and array of destination content ids as value.
     *
     * Example:
     * <code>
     *  array(
     *      \eZ\Publish\API\Repository\Values\Content\Relation::LINK => array(
     *          "contentIds" => array( 12, 13, 14 ),
     *          "locationIds" => array( 24 )
     *      ),
     *      \eZ\Publish\API\Repository\Values\Content\Relation::EMBED => array(
     *          "contentIds" => array( 12 ),
     *          "locationIds" => array( 24, 45 )
     *      ),
     *      \eZ\Publish\API\Repository\Values\Content\Relation::FIELD => array( 12 )
     *  )
     * </code>
     */
    public function getRelations(SPIValue $value)
    {
        $relations = [];

        /** @var \EzSystems\EzPlatformRichText\eZ\FieldType\RichText\Value $value */
        if ($value->xml instanceof DOMDocument) {
            $relations = [
                Relation::LINK => $this->getRelatedObjectIds($value, Relation::LINK),
                Relation::EMBED => $this->getRelatedObjectIds($value, Relation::EMBED),
            ];
        }

        return $relations;
    }

    /**
     * {@inheritdoc}
     */
    protected function getRelatedObjectIds(Value $fieldValue, $relationType)
    {
        if ($relationType === Relation::EMBED) {
            $tagNames = ['ezembedinline', 'ezembed'];
        } else {
            $tagNames = ['link', 'ezlink'];
        }

        $contentIds = [];
        $locationIds = [];
        $xpath = new \DOMXPath($fieldValue->xml);
        $xpath->registerNamespace('docbook', 'http://docbook.org/ns/docbook');

        foreach ($tagNames as $tagName) {
            $xpathExpression = "//docbook:{$tagName}[starts-with( @xlink:href, 'ezcontent://' ) or starts-with( @xlink:href, 'ezlocation://' )]";
            /** @var \DOMElement $element */
            foreach ($xpath->query($xpathExpression) as $element) {
                preg_match('~^(.+)://([^#]*)?(#.*|\\s*)?$~', $element->getAttribute('xlink:href'), $matches);
                list(, $scheme, $id) = $matches;

                if (empty($id)) {
                    continue;
                }

                if ($scheme === 'ezcontent') {
                    $contentIds[] = $id;
                } elseif ($scheme === 'ezlocation') {
                    $locationIds[] = $id;
                }
            }
        }

        return [
            'locationIds' => array_unique($locationIds),
            'contentIds' => array_unique($contentIds),
        ];
    }
}