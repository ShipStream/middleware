<?php
/**
 * Magento
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/osl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@magentocommerce.com so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade Magento to newer
 * versions in the future. If you wish to customize Magento for your
 * needs please refer to http://www.magentocommerce.com for more information.
 *
 * @category   Varien
 * @package    Varien_Simplexml
 * @copyright  Copyright (c) 2008 Irubin Consulting Inc. DBA Varien (http://www.varien.com)
 * @license    http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */


/**
 * Extends SimpleXML to add valuable functionality to SimpleXMLElement class
 *
 * @category   Varien
 * @package    Varien_Simplexml
 * @author      Magento Core Team <core@magentocommerce.com>
 */
class Varien_Simplexml_Element extends SimpleXMLElement
{

    /**
     * Enter description here...
     *
     * @return boolean
     */
    public function hasChildren(): bool
    {
        if (!$this->children()) {
            return false;
        }

        // simplexml bug: @attributes is in children() but invisible in foreach
        foreach ($this->children() as $k=>$child) {
            return true;
        }
        return false;
    }

    /**
     * Returns attribute value by attribute name
     *
     * @param string $name
     * @return string
     */
    public function getAttribute($name)
    {
        $attrs = $this->attributes();
        return isset($attrs[$name]) ? (string)$attrs[$name] : null;
    }

    /**
     * Find a descendant of a node by path
     *
     * @todo    Do we need to make it xpath look-a-like?
     * @todo    Check if we still need all this and revert to plain XPath if this makes any sense
     * @todo    param string $path Subset of xpath. Example: "child/grand[@attrName='attrValue']/subGrand"
     * @param   string $path Example: "child/grand@attrName=attrValue/subGrand" (to make it faster without regex)
     * @return  Varien_Simplexml_Element
     */
    public function descend($path)
    {
        # $node = $this->xpath($path);
        # return $node[0];
        if (is_array($path)) {
            $pathArr = $path;
        } else {
            // Simple exploding by / does not suffice,
            // as an attribute value may contain a / inside
            // Note that there are three matches for different kinds of attribute values specification
            if(strpos($path, "@") === false) {
                $pathArr = explode('/', $path);
            }
            else {
                $regex = "#([^@/\\\"]+(?:@[^=/]+=(?:\\\"[^\\\"]*\\\"|[^/]*))?)/?#";
                $pathArr = $pathMatches = array();
                if(preg_match_all($regex, $path, $pathMatches)) {
                    $pathArr = $pathMatches[1];
                }
            }
        }
        $desc = $this;
        foreach ($pathArr as $nodeName) {
            if (strpos($nodeName, '@')!==false) {
                $a = explode('@', $nodeName);
                $b = explode('=', $a[1]);
                $nodeName = $a[0];
                $attributeName = $b[0];
                $attributeValue = $b[1];
                //
                // Does a very simplistic trimming of attribute value.
                //
                $attributeValue = trim($attributeValue, '"');
                $found = false;
                foreach ($desc->$nodeName as $subdesc) {
                    if ((string)$subdesc[$attributeName]===$attributeValue) {
                        $found = true;
                        $desc = $subdesc;
                        break;
                    }
                }
                if (!$found) {
                    $desc = false;
                }
            } else {
                $desc = $desc->$nodeName;
            }
            if (!$desc) {
                return false;
            }
        }
        return $desc;
    }

    /**
     * Returns the node and children as an array
     *
     * @return array|string
     */
    public function asArray()
    {
        return $this->_asArray();
    }

    /**
     * asArray() analog, but without attributes
     * @return array|string
     */
    public function asCanonicalArray()
    {
        return $this->_asArray(true);
    }

    /**
     * Returns the node and children as an array
     *
     * @param bool $isCanonical - whether to ignore attributes
     * @return array|string
     */
    protected function _asArray($isCanonical = false)
    {
        $result = array();
        if (!$isCanonical) {
            // add attributes
            foreach ($this->attributes() as $attributeName => $attribute) {
                if ($attribute) {
                    $result['@'][$attributeName] = (string)$attribute;
                }
            }
        }
        // add children values
        if ($this->hasChildren()) {
            foreach ($this->children() as $childName => $child) {
                $result[$childName] = $child->_asArray($isCanonical);
            }
        } else {
            if (empty($result)) {
                // return as string, if nothing was found
                $result = (string) $this;
            } else {
                // value has zero key element
                $result[0] = (string) $this;
            }
        }
        return $result;
    }

    /**
     * Makes nicely formatted XML from the node
     *
     * @param string $filename
     * @param int|boolean $level if false
     * @return string
     */
    public function asNiceXml($filename='', $level=0)
    {
        if (is_numeric($level)) {
            $pad = str_pad('', $level*3, ' ', STR_PAD_LEFT);
            $nl = "\n";
        } else {
            $pad = '';
            $nl = '';
        }

        $out = $pad.'<'.$this->getName();

        if ($attributes = $this->attributes()) {
            foreach ($attributes as $key=>$value) {
                $out .= ' '.$key.'="'.str_replace('"', '\"', (string)$value).'"';
            }
        }

        if ($this->hasChildren()) {
            $out .= '>'.$nl;
            foreach ($this->children() as $child) {
                $out .= $child->asNiceXml('', is_numeric($level) ? $level+1 : true);
            }
            $out .= $pad.'</'.$this->getName().'>'.$nl;
        } else {
            $value = (string)$this;
            if (strlen($value)) {
                $out .= '>'.$this->xmlentities($value).'</'.$this->getName().'>'.$nl;
            } else {
                $out .= '/>'.$nl;
            }
        }

        if ((0===$level || false===$level) && !empty($filename)) {
            file_put_contents($filename, $out);
        }

        return $out;
    }

    /**
     * Enter description here...
     *
     * @param int $level
     * @return string
     */
    public function innerXml($level=0)
    {
        $out = '';
        foreach ($this->children() as $child) {
            $out .= $child->asNiceXml($level);
        }
        return $out;
    }

    /**
     * Converts meaningful xml characters to xml entities
     *
     * @param  string
     * @return string
     */
    public function xmlentities($value = null)
    {
        if (is_null($value)) {
            $value = $this;
        }
        $value = (string)$value;

        $value = str_replace(array('&', '"', "'", '<', '>'), array('&amp;', '&quot;', '&apos;', '&lt;', '&gt;'), $value);

        return $value;
    }

    /**
     * Appends $source to current node
     *
     * @param Varien_Simplexml_Element $source
     * @return Varien_Simplexml_Element
     */
    public function appendChild($source)
    {
        if ($source->children()) {
            $name = $source->getName();
            $child = $this->addChild($name);
        } else {
            $child = $this->addChild($source->getName(), $this->xmlentities($source));
        }

        $attributes = $source->attributes();
        foreach ($attributes as $key=>$value) {
            $child->addAttribute($key, $this->xmlentities($value));
        }

        foreach ($source->children() as $sourceChild) {
            $child->appendChild($sourceChild);
        }
        return $this;
    }

    /**
     * Extends current node with xml from $source
     *
     * If $overwrite is false will merge only missing nodes
     * Otherwise will overwrite existing nodes
     *
     * @param Varien_Simplexml_Element $source
     * @param boolean $overwrite
     * @return Varien_Simplexml_Element
     */
    public function extend($source, $overwrite=false)
    {
        if (!$source instanceof Varien_Simplexml_Element) {
            return $this;
        }

        foreach ($source->children() as $child) {
            $this->extendChild($child, $overwrite);
        }

        return $this;
    }

    /**
     * Extends one node
     *
     * @param Varien_Simplexml_Element $source
     * @param boolean $overwrite
     * @return Varien_Simplexml_Element
     */
    public function extendChild($source, $overwrite=false)
    {
        // this will be our new target node
        $targetChild = null;

        // name of the source node
        $sourceName = $source->getName();

        // here we have children of our source node
        $sourceChildren = $source->children();

        if (!$source->hasChildren()) {
            // handle string node
            if (isset($this->$sourceName)) {
                // if target already has children return without regard
                if ($this->$sourceName->children()) {
                    /** Custom. Use config value without applying backend models etc. */
                    //return $this;
                }
                if ($overwrite) {
                    unset($this->$sourceName);
                } else {
                    return $this;
                }
            }

            $targetChild = $this->addChild($sourceName, $source->xmlentities());
            foreach ($source->attributes() as $key=>$value) {
                $targetChild->addAttribute($key, $this->xmlentities($value));
            }
            return $this;
        }

        if (isset($this->$sourceName)) {
            $targetChild = $this->$sourceName;
        }

        if (is_null($targetChild)) {
            // if child target is not found create new and descend
            $targetChild = $this->addChild($sourceName);
            foreach ($source->attributes() as $key=>$value) {
                $targetChild->addAttribute($key, $this->xmlentities($value));
            }
        }

        // finally add our source node children to resulting new target node
        foreach ($sourceChildren as $childKey=>$childNode) {
            $targetChild->extendChild($childNode, $overwrite);
        }

        return $this;
    }

    /**
     * @param $path
     * @param $value
     * @param bool $overwrite
     * @return $this
     */
    public function setNode($path, $value, $overwrite=true)
    {
        $arr1 = explode('/', $path);
        $arr = array();
        foreach ($arr1 as $v) {
            if (!empty($v)) $arr[] = $v;
        }
        $last = sizeof($arr)-1;
        $node = $this;
        foreach ($arr as $i=>$nodeName) {
            if ($last===$i) {
                /*
                if (isset($xml->$nodeName)) {
                    if ($overwrite) {
                        unset($xml->$nodeName);
                    } else {
                        continue;
                    }
                }
                $xml->addChild($nodeName, $xml->xmlentities($value));
                */
                if (!isset($node->$nodeName) || $overwrite) {
                    $node->$nodeName = $value;
                }
            } else {
                if (!isset($node->$nodeName)) {
                    $node = $node->addChild($nodeName);
                } else {
                    $node = $node->$nodeName;
                }
            }

        }
        return $this;
    }

}
