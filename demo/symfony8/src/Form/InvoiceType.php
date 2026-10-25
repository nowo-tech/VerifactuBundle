<?php

declare(strict_types=1);

namespace App\Form;

use Nowo\VerifactuBundle\Model\RecordType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Demo invoice generator (REQ-TWIG-005): flat field names numserie / fecha / record_type / tipo / cuota / importe /
 * description / submit_aeat, as the demo controller and the docs use them. Issuing a record appends to the persistent
 * hash chain, so the form carries a CSRF token (token id "demo_invoice").
 *
 * @extends AbstractType<array<string, mixed>>
 */
final class InvoiceType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('numserie', TextType::class, ['label' => 'Serie + número'])
            ->add('fecha', TextType::class, ['label' => 'Fecha expedición (dd-mm-yyyy)'])
            ->add('record_type', ChoiceType::class, [
                'label'   => 'Tipo operación',
                'choices' => [
                    'Alta (nueva factura)' => RecordType::Alta->value,
                    'Anulación'            => RecordType::Anulacion->value,
                ],
            ])
            ->add('tipo', TextType::class, ['label' => 'Tipo factura'])
            ->add('cuota', TextType::class, ['label' => 'Cuota total'])
            ->add('importe', TextType::class, ['label' => 'Importe total'])
            ->add('description', TextareaType::class, [
                'label'    => 'Descripción operación',
                'required' => false,
                'attr'     => ['rows' => 2],
            ]);

        if ($options['aeat_configured']) {
            $builder->add('submit_aeat', CheckboxType::class, [
                'label'    => 'Enviar a AEAT sandbox',
                'required' => false,
            ]);
        }
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
