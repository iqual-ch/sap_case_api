<?php

namespace Drupal\Tests\sap_case_api\Unit;

use Drupal\sap_case_api\CaseRequestBuilder;
use Drupal\sap_case_api\Exception\CaseApiException;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\webform\WebformSubmissionInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\LoggerInterface;

/**
 * Tests that submissions are mapped onto the Case API schema correctly.
 *
 * The API rejects a request outright when a type or a length is wrong, and a
 * rejection is only visible in the log after the visitor has left. These tests
 * pin the conversions the contract requires: real booleans, SAP salutation
 * codes, date and time formats, and the maximum lengths per property.
 */
#[Group('sap_case_api')]
#[CoversClass(CaseRequestBuilder::class)]
class CaseRequestBuilderTest extends UnitTestCase {

  /**
   * The builder under test.
   *
   * @var \Drupal\sap_case_api\CaseRequestBuilder
   */
  protected CaseRequestBuilder $builder;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->builder = new CaseRequestBuilder(
      $this->createMock(EntityTypeManagerInterface::class),
      $this->createMock(TimeInterface::class),
      $this->createMock(LoggerInterface::class)
    );
  }

  /**
   * Builds a payload from the given submission values.
   *
   * @param array $data
   *   The submitted element values.
   * @param array $settings
   *   Handler settings overriding the defaults.
   *
   * @return array
   *   The built payload.
   */
  protected function build(array $data, array $settings = []): array {
    $submission = $this->createMock(WebformSubmissionInterface::class);
    $submission->method('getElementData')
      ->willReturnCallback(fn ($key) => $data[$key] ?? NULL);
    $submission->method('uuid')->willReturn('11111111-2222-3333-4444-555555555555');
    $submission->method('getCreatedTime')->willReturn(1756540800);
    $submission->method('id')->willReturn(42);

    return $this->builder->build($submission, $settings + [
      'contact_form_variant' => 'Example variant',
      'subject' => 'Contact form',
      'attachment_elements' => '',
      'mapping' => implode("\n", [
        'customer.familyName: name',
        'customer.givenName: vorname',
        'customer.eMail: e_mail',
        'case.content: bemerkungen',
      ]),
    ]);
  }

  /**
   * The minimum viable submission produces every required property.
   */
  public function testRequiredPropertiesArePresent(): void {
    $payload = $this->build([
      'name' => 'Muster',
      'vorname' => 'Erika',
      'e_mail' => 'erika@example.com',
      'bemerkungen' => 'Eine Rückmeldung.',
    ]);

    $this->assertSame('11111111-2222-3333-4444-555555555555', $payload['requestId']);
    $this->assertSame('Example variant', $payload['contactFormVariant']);
    $this->assertSame('Contact form', $payload['case']['subject']);
    $this->assertSame('Muster', $payload['customer']['familyName']);
    $this->assertSame('Eine Rückmeldung.', $payload['case']['content']);
    $this->assertNotEmpty($payload['reportedOn']);
  }

  /**
   * A missing required property fails before the request is ever sent.
   *
   * A mapping typo must not turn into a queued delivery that retries five
   * times against a payload the API can only reject.
   */
  public function testMissingRequiredPropertyThrowsPermanentFailure(): void {
    $this->expectException(CaseApiException::class);
    $this->expectExceptionMessageMatches('/customer\.eMail/');

    try {
      $this->build([
        'name' => 'Muster',
        'vorname' => 'Erika',
        'bemerkungen' => 'Eine Rückmeldung.',
      ]);
    }
    catch (CaseApiException $e) {
      $this->assertTrue($e->isPermanent(), 'An unmappable submission is not retried.');
      throw $e;
    }
  }

  /**
   * Empty optional values are omitted rather than sent as empty strings.
   */
  public function testEmptyOptionalValuesAreOmitted(): void {
    $payload = $this->build([
      'name' => 'Muster',
      'vorname' => 'Erika',
      'e_mail' => 'erika@example.com',
      'bemerkungen' => 'Eine Rückmeldung.',
      'haltestelle' => '',
    ], [
      'mapping' => implode("\n", [
        'customer.familyName: name',
        'customer.givenName: vorname',
        'customer.eMail: e_mail',
        'case.content: bemerkungen',
        'case.stopDescription: haltestelle',
      ]),
    ]);

    $this->assertArrayNotHasKey('stopDescription', $payload['case']);
  }

  /**
   * Strings longer than the schema allows are truncated, not rejected.
   */
  public function testValuesAreTruncatedToTheSchemaLength(): void {
    $payload = $this->build([
      'name' => str_repeat('a', 80),
      'vorname' => 'Erika',
      'e_mail' => 'erika@example.com',
      'bemerkungen' => 'Eine Rückmeldung.',
    ]);

    $this->assertSame(40, mb_strlen($payload['customer']['familyName']));
  }

  /**
   * Salutations become the SAP form-of-address codes.
   */
  #[DataProvider('providerFormOfAddress')]
  public function testFormOfAddressMapping(string $submitted, string $expected): void {
    $payload = $this->build([
      'name' => 'Muster',
      'vorname' => 'Erika',
      'e_mail' => 'erika@example.com',
      'bemerkungen' => 'Eine Rückmeldung.',
      'anrede' => $submitted,
    ], [
      'mapping' => implode("\n", [
        'customer.familyName: name',
        'customer.givenName: vorname',
        'customer.eMail: e_mail',
        'case.content: bemerkungen',
        'customer.formOfAddress: anrede|form_of_address',
      ]),
    ]);

    $this->assertSame($expected, $payload['customer']['formOfAddress']);
  }

  /**
   * Provides salutations and the codes the API expects for them.
   *
   * @return array
   *   Test cases.
   */
  public static function providerFormOfAddress(): array {
    return [
      'Frau' => ['Frau', '0001'],
      'Herr' => ['Herr', '0002'],
      'neutral' => ['Neutrale Anrede (Vorname, Name)', 'Z001'],
    ];
  }

  /**
   * The answer-requested radio becomes a real JSON boolean.
   */
  #[DataProvider('providerResponseRequested')]
  public function testResponseRequestedBecomesBoolean(string $submitted, bool $expected): void {
    $payload = $this->build([
      'name' => 'Muster',
      'vorname' => 'Erika',
      'e_mail' => 'erika@example.com',
      'bemerkungen' => 'Eine Rückmeldung.',
      'antwort_erwunscht' => $submitted,
    ], [
      'mapping' => implode("\n", [
        'customer.familyName: name',
        'customer.givenName: vorname',
        'customer.eMail: e_mail',
        'case.content: bemerkungen',
        'case.responseRequested: antwort_erwunscht|boolean:Ich wünsche eine Antwort',
      ]),
    ]);

    $this->assertIsBool($payload['case']['responseRequested']);
    $this->assertSame($expected, $payload['case']['responseRequested']);
  }

  /**
   * Provides the radio options of the contact form.
   *
   * @return array
   *   Test cases.
   */
  public static function providerResponseRequested(): array {
    return [
      'wants an answer' => ['Ich wünsche eine Antwort', TRUE],
      'wants no answer' => ['Ich wünsche keine Antwort', FALSE],
    ];
  }

  /**
   * Dates and times are reformatted to the formats the schema documents.
   */
  public function testDateAndTimeAreReformatted(): void {
    $payload = $this->build([
      'name' => 'Muster',
      'vorname' => 'Erika',
      'e_mail' => 'erika@example.com',
      'bemerkungen' => 'Eine Rückmeldung.',
      'datum_des_vorfalls' => '2026-08-30',
      'uhrzeit' => '14:35',
    ], [
      'mapping' => implode("\n", [
        'customer.familyName: name',
        'customer.givenName: vorname',
        'customer.eMail: e_mail',
        'case.content: bemerkungen',
        'case.incidentDate: datum_des_vorfalls|date',
        'case.incidentTime: uhrzeit|time',
      ]),
    ]);

    $this->assertSame('2026-08-30', $payload['case']['incidentDate']);
    $this->assertSame('14:35:00', $payload['case']['incidentTime']);
  }

  /**
   * A composite element such as the email confirmation yields a single value.
   */
  public function testCompositeElementYieldsScalar(): void {
    $payload = $this->build([
      'name' => 'Muster',
      'vorname' => 'Erika',
      'e_mail' => ['mail' => 'erika@example.com', 'mail_2' => 'erika@example.com'],
      'bemerkungen' => 'Eine Rückmeldung.',
    ]);

    $this->assertSame('erika@example.com', $payload['customer']['eMail']);
  }

  /**
   * Comments and blank lines in the mapping are ignored.
   */
  public function testParseMappingIgnoresCommentsAndBlankLines(): void {
    $mapping = $this->builder->parseMapping("# a comment\n\ncase.content: bemerkungen\n");

    $this->assertSame(['case.content'], array_keys($mapping));
    $this->assertSame('bemerkungen', $mapping['case.content']['element']);
  }

  /**
   * A transform argument may itself contain a colon.
   */
  public function testParseMappingKeepsTransformArguments(): void {
    $mapping = $this->builder->parseMapping('case.responseRequested: antwort|boolean:Ja: klar');

    $this->assertSame('antwort', $mapping['case.responseRequested']['element']);
    $this->assertSame('boolean', $mapping['case.responseRequested']['transform']);
    $this->assertSame('Ja: klar', $mapping['case.responseRequested']['argument']);
  }

}
