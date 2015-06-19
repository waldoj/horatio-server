<?php

/*
 * Create an empty array to encode our response.
 */
$response = array();

/*
 * If there's no file, there's nothing to be done.
 */
if (!isset($uploaded_file))
{

    $response['valid'] = FALSE;
    $response['errors'] = 'No file provided.';
    echo json_encode($response);
    exit();

}

/*
 * Change the variable name for the absentee ballot application.
 */
$ab = $uploaded_file;
unset($uploaded_file);

/*
 * Validate the submitted JSON.
 */
$retriever = new JsonSchema\Uri\UriRetriever;
$schema = $retriever->retrieve('file://' . realpath('includes/schema.json'));
$validator = new JsonSchema\Validator();
$validator->check($ab, $schema);

/*
 * If the JSON is not valid, return an error and halt.
 */
if ($validator->isValid() === FALSE)
{

    $response['valid'] = FALSE;
    $response['errors'] = array();
    foreach ($validator->getErrors() as $error)
    {

    	if (empty($error['property']))
    	{
    		$error['property'] = 'undefined';
    	}

        $response['errors'][$error{'property'}] = $error['message'];

    }

	$json = json_encode($response);
	echo $json;
	exit();

}

/*
 * Generate a unique ID for this ballot. A 32-digit hash is excessive, so we just use the first 10
 * digits.
 */
$ab_id = substr(md5(json_encode($ab)), 0, 10);

/*
 * Identify the registrar to whom this application should be sent.
 */
$gnis_id = $ab->election->locality_gnis;
$registrars = json_decode(file_get_contents('includes/registrars.json'));
$registrar_email = $registrars->$gnis_id->email;

/*
 * Save this application as a PDF.
 */
$values = $ab;
require('includes/pdf_generation.inc.php');

/*
 * Send the PDF to the site operator if the site is in debug mode.
 */
if (DEBUG_MODE === TRUE)
{
	$registrar_email = SITE_EMAIL;
}

use Mailgun\Mailgun;
$mg = new Mailgun(MAILGUN_API_KEY);

/*
 * Assemble and send the message.
 */
$mg->sendMessage(MAILGUN_DOMAIN, array('from'    => SITE_EMAIL, 
                                'to'      => $registrar_email,
                                'subject' => 'Absentee Ballot Request', 
                                'text'    => 'Please find attached an absentee ballot request.'),
								array('attachment' =>
									array(
										array(
											'filePath'		=> '/vol/jaquith.org/htdocs/api/applications/' . $ab_id . '.pdf',
											'remoteName'	=> 'ab-' . $ab_id
										)
									)
								)
							);

/*
 * If there was an error in the process of sending the message, report that to the client.
 */
$result = $mg->get(MAILGUN_DOMAIN . '/log', array('limit' => 1));
if ($result->http_response_code != '200')
{

	$response['valid'] = TRUE;
	$response['success'] = FALSE;
    $response['errors'] = 'Could not send email. ' . $result->http_response_body->items[0]->message;
    echo json_encode($response);

    /*
     * Also, send a note to the site operator.
     */
	$mg->sendMessage(MAILGUN_DOMAIN, array('from'    => SITE_EMAIL, 
			                                'to'      => SITE_EMAIL,
			                                'subject' => 'Absentee Ballot Request Failed', 
			                                'text'    => 'A submitted absentee ballot request on '
			                                	. SITE_URL . ' just failed to be sent via email,
			                                	and requires manual intervention. See ' . $ab_id
			                                	. 'at ' . SITE_URL . 'applications/' . $ab_id .
			                                	'.pdf')
										);
    
    exit();

}

/*
 * Inform the client of the success.
 */
$response['valid'] = TRUE;
$response['id'] = $ab_id;
$response['pdf_url'] = SITE_URL . 'applications/' . $ab_id . '.pdf';
$response['registrar'] = (array) $registrars->$gnis_id;

/*
 * Send a response to the browser.
 */
$json = json_encode($response);
if ($json === FALSE)
{
	$response['errors'] = TRUE;
	$json = json_encode($response);
}
echo $json;
