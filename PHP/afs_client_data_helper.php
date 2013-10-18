<?php
require_once "afs_text_visitor.php";
require_once "afs_helper_base.php";
require_once "afs_tools.php";


/** @brief Manage client data.
 *
 * Instances of this class allow to manage one or more XML and JSON client
 * data.*/
class AfsClientDataManager
{
    private $client_data = array();

    /** @brief Construct new manager with all necessary client data helpers.
     *
     * One or more client data helper can be created and managed.
     * @param $reply [in] root reply element.
     */
    public function __construct($reply)
    {
        foreach ($reply->clientData as $data) {
            $helper = AfsClientDataHelperFactory::create($data);
            $this->client_data[$helper->id] = $helper;
        }
    }

    /** @brief Retrieve text from the appropriate client data.
     *
     * @param $id [in] client data id.
     * @param $name [in] name or XPath of the required element for JSON
     *        respectively XML clent data.
     * @param $formatter [in] used for highlighted content (default=null,
     *        appropriate formatter is instanced for JSON and XML).
     *
     * @return client data as text.
     */
    public function get_text($id, $name=null, $formatter=null)
    {
        if (array_key_exists($id, $this->client_data)) {
            return $this->client_data[$id]->get_text($name, $formatter);
        } else {
            throw new OutOfBoundsException('No client data with id \'' . $id
                . '\' found.');
        }
    }
}


/** @brief Client data interface. */
interface AfsClientDataHelperInterface
{
    /** @brief Retrieve client data as text.
     *
     * All client data or sub-tree can be retrieved depending on @a path
     * parameter.
     * @param $name [in] data name to be extracted (default=null, retrieve
     *        all client data).
     * @param $formatter [in] format output string. It is used when highlight in
     *        client data is activated. See implementation to provide
     *        appropriate formatter (default=null, default formatter is used).
     * @return client data as text.
     */
    public function get_text($name, $formatter);
}

/** @brief Base class  for client data helpers. */
abstract class AfsClientDataHelperBase extends AfsHelperBase
{
    private $id = null;

    /** @brief Construct base class instance.
     * @param $client_data [in] client data used to retrieve the right id.
     */
    public function __construct($client_data)
    {
        $this->id = $client_data->id;
    }

    /** @brief Retrieve client data id.
     * @return id associated to client data.
     */
    public function get_id()
    {
        return $this->id;
    }
}


/** @brief Factory for client data helper. */
class AfsClientDataHelperFactory
{
    /** @brief Create appropriate client data helper.
     * @param $client_data [in] client data entry point.
     * @return appropriate client data helper.
     * @exception Exception invalid @a client_data parameter provided
     */
    public static function create($client_data)
    {
        if (! property_exists($client_data, 'mimeType')) {
            throw new Exception('No mime-type available for provided client data.');
        } elseif (! property_exists($client_data, 'contents')) {
            throw new Exception('No content available for provided client data.');
        } elseif ($client_data->mimeType == 'text/xml'
            || $client_data->mimeType == 'application/xml') {
            return new AfsXmlClientDataHelper($client_data);
        } elseif ($client_data->mimeType == 'text/json'
            || $client_data->mimeType == 'application/json') {
            return new AfsJsonClientDataHelper($client_data);
        } else {
            throw new Exception('Unmanaged client data type: ' . $client_data->mimeType);
        }
    }
}


/** @brief XML client data helper. */
class AfsXmlClientDataHelper extends AfsClientDataHelperBase implements AfsClientDataHelperInterface
{
    private $client_data = null;
    private $has_highlight = false;
    private $doc = null;
    private static $afs_ns = 'http://ref.antidot.net/v7/afs#';

    /** @brief Construct new instance of XML helper.
     * @param $client_data [in] input data used to initialize the instance.
     */
    public function __construct($client_data)
    {
        parent::__construct($client_data);

        $this->client_data = $client_data;
        // Client data content is not XML valid when highlight is activated for
        // client data and a match occurs: no afs namespace prefix is defined!
        // So check whether afs prefix namespace is used
        if (strpos($client_data->contents, '<afs:match>') === false) {
            $contents = $client_data->contents;
        } else {
            $contents = str_replace_first('>',
                ' xmlns:afs="' . AfsXmlClientDataHelper::$afs_ns . '">',
                $client_data->contents);
            $this->has_highlight = true;
        }
        $this->doc = new DOMDocument();
        $this->doc->loadXML($contents);
    }

    /** @brief Retrieve text from XML node.
     *
     * @param $path [in] XPath to apply (default=null, retrieve all content as
     *        text).
     * @param $highlight_callback [in] callback to emphase text when highlight of
     *        client data is activated. It should be of @a FilterNode type
     *        (default=null, instance of @a FilterNode is used).
     *
     * @return text of specific node(s) depending on parameters.
     *
     * @remark XPath which points on unknown node can not be differenciated from
     * XPath pointing on empty node. So, in both cases empty string is returned.
     */
    public function get_text($path=null, $highlight_callback=null)
    {
        if (is_null($path)) {
            return $this->client_data->contents;
        } else {
            $xpath = new DOMXPath($this->doc);
            $result = $xpath->query($path);
            if ($result->length == 0) {
                return '';
            } elseif ($this->has_highlight) {
                return $this->get_highlighted_text($result->item(0), $highlight_callback);
            } else {
                return DOMNodeHelper::get_text($result->item(0));
            }
        }
    }

    private function get_highlighted_text($node, $callback)
    {
        if (is_null($callback)) {
            $callback = new BoldFilterNode('match',
                AfsXmlClientDataHelper::$afs_ns);
        }
        return DOMNodeHelper::get_text($node, array(XML_ELEMENT_NODE => $callback));
    }
}


/** @brief Helper for client data in JSON format. */
class AfsJsonClientDataHelper extends AfsClientDataHelperBase implements AfsClientDataHelperInterface
{
    private $client_data;

    /** @brief Construct new instance of JSON helper.
     * @param $client_data  [in] input data used to initialize the instance.
     */
    public function __construct($client_data)
    {
        parent::__construct($client_data);

        $this->client_data = $client_data;
    }

    /** @brief Retrieve text from JSON content.
     *
     * @param $name [in] name of the first element to retrieve (default=null,
     *        all JSON content is returned as text). Empty string allows to
     *        retrieve text content correctly formatted when highlight is
     *        activated.
     * @param $visitor [in] instance of @a AfsTextVisitorInterface used to format
     *        appropriately text content when highlight has been activated
     *        (default=null, @a AfsTextVisitor is used).
     *
     * @return formatted text.
     *
     * @par Example with name=null:
     * Input JSON client data:
     * @verbatim
       {
         "clientData": [
           {
             "contents": { "data": [ "afs:t": "KwicString", "text": "some text" ] },
             "id": "data1",
             "mimeType": "application/json"
           }
         ]
       }
       @endverbatim
     * Call to <tt>get_text(null)</tt> will return
     * @verbatim {"data":["afs:t":"KwicString","text":"some text"]}@endverbatim.
     *
     * @par Example with name='data':
     * Same input JSON as previous example:
     * @verbatim
       {
         "clientData": [
           {
             "contents": { "data": [ "afs:t": "KwicString", "text": "some text" ] },
             "id": "data1",
             "mimeType": "application/json"
           }
         ]
       }
       @endverbatim
     * Call to <tt>get_text('data')</tt> will return
     * @verbatim some text @endverbatim.
     *
     * @par Example with name='':
     * Client data is a @em simple text:
     * @verbatim
       {
         "clientData": [
           {
             "contents": [ { "afs:t": "KwicString", "text": "some text" } ],
             "id": "data1",
             "mimeType": "application/json"
           }
         ]
       }
       @endverbatim
     * Call to <tt>get_text('')</tt> will return
     * @verbatim some text @endverbatim.
     */
    public function get_text($name=null, $visitor=null)
    {
        if (is_null($visitor)) {
            $visitor = new AfsTextVisitor();
        }
        $contents = $this->client_data->contents;
        if (is_null($name)) {
            return json_encode($contents);
        } else {
            if (! is_array($contents)) {
                if (property_exists($contents, $name)) {
                    $text_mgr = new AfsTextManager($contents->$name);
                } else {
                    error_log('No client data content named: ' . $name);
                    return '';
                }
            } else {
                $text_mgr = new AfsTextManager($contents);
            }
            return $text_mgr->visit_text($visitor);
        }
    }
}

?>