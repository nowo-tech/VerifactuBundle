<?php

declare(strict_types=1);

namespace App\Form;

use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Nowo\VerifactuBundle\Model\RecordType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Demo invoice generator (REQ-TWIG-005): flat field names numserie / fecha / record_type / tipo / cuota / importe /
 * description / submit_aeat, as the demo controller and the docs use them. Issuing a record appends to the persistent
 * hash chain, so the form carries a CSRF token (token id "demo_invoice"). REQ-DEMO-015: a FormKit form (profile `demo`)
 * on UiKit's form theme.
 *
 * @extends AbstractType<array<string, mixed>>
 */
#[FormKitConfig('demo')]
final class InvoiceType extends AbstractType
{
    use FormOptionsTrait;

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $plain = ['translation_domain' => false, 'placeholder' => false, 'help' => false];

        $this->addText($builder, 'numserie', $plain + ['label' => 'Serie + número']);
        $this->addText($builder, 'fecha', $plain + ['label' => 'Fecha expedición (dd-mm-yyyy)']);
        $this->addChoice($builder, 'record_type', $plain + [
            'label'   => 'Tipo operación',
            'choices' => [
                'Alta (nueva factura)' => RecordType::Alta->value,
                'Anulación'            => RecordType::Anulacion->value,
            ],
        ]);
        $this->addText($builder, 'tipo', $plain + ['label' => 'Tipo factura']);
        $this->addText($builder, 'cuota', $plain + ['label' => 'Cuota total']);
        $this->addText($builder, 'importe', $plain + ['label' => 'Importe total']);
        $this->addTextarea($builder, 'description', $plain + [
            'label'    => 'Descripción operación',
            'required' => false,
            'attr'     => ['rows' => 2],
        ]);

        if ($options['aeat_configured']) {
            $this->addCheckbox($builder, 'submit_aeat', $plain + [
                'label'    => 'Enviar a AEAT sandbox',
                'required' => false,
            ]);
        }

        $this->addWithDefaults($builder, 'submit', SubmitType::class, [
            'label'              => 'Emitir factura Veri*Factu',
            'translation_domain' => false,
            'attr'               => ['class' => 'nowo-ui-btn nowo-ui-btn--primary'],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'method'          => 'POST',
            'csrf_protection' => true,
            'csrf_token_id'   => 'demo_invoice',
            'aeat_configured' => false,
        ]);
        $resolver->setAllowedTypes('aeat_configured', 'bool');
    }

    public function getBlockPrefix(): string
    {
        return '';
    }
}
