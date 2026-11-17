<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Formulaire de contact. Les contraintes métier sont portées par FormsCore\DTO\Mail\ContactRequestMailDto
 * (validées par ContactService) ; le formulaire ne gère que la saisie, le CSRF et le pot de miel.
 */
final class ContactType extends AbstractType
{
    public const SUBJECTS = [
        'Demande de démonstration' => 'Demande de démonstration',
        'Question sur les tarifs' => 'Question sur les tarifs',
        'Besoin spécifique (volume, intégration)' => 'Besoin spécifique (volume, intégration)',
        'Support' => 'Support',
        'Autre' => 'Autre',
    ];

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, ['label' => 'Nom', 'attr' => ['autocomplete' => 'name', 'maxlength' => 100]])
            ->add('email', EmailType::class, ['label' => 'Adresse e-mail', 'attr' => ['autocomplete' => 'email']])
            ->add('subject', ChoiceType::class, ['label' => 'Objet', 'choices' => self::SUBJECTS, 'placeholder' => 'Choisissez un objet'])
            ->add('message', TextareaType::class, ['label' => 'Message', 'attr' => ['rows' => 6, 'maxlength' => 5000]])
            // Pot de miel : champ invisible pour un humain, rempli par les robots
            ->add('website', TextType::class, ['label' => 'Ne pas remplir ce champ', 'required' => false, 'mapped' => true, 'attr' => ['autocomplete' => 'off', 'tabindex' => '-1']]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_token_id' => 'contact',
            'attr' => ['novalidate' => 'novalidate'],
        ]);
    }
}
