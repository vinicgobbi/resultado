<?php

namespace App\Enums;

enum State: string
{
    case Acre = 'ac';
    case Alagoas = 'al';
    case Amapa = 'ap';
    case Amazonas = 'am';
    case Bahia = 'ba';
    case Ceara = 'ce';
    case DistritoFederal = 'df';
    case EspiritoSanto = 'es';
    case Goias = 'go';
    case Maranhao = 'ma';
    case MatoGrosso = 'mt';
    case MatoGrossoDoSul = 'ms';
    case MinasGerais = 'mg';
    case Para = 'pa';
    case Paraiba = 'pb';
    case Parana = 'pr';
    case Pernambuco = 'pe';
    case Piaui = 'pi';
    case RioDeJaneiro = 'rj';
    case RioGrandeDoNorte = 'rn';
    case RioGrandeDoSul = 'rs';
    case Rondonia = 'ro';
    case Roraima = 'rr';
    case SantaCatarina = 'sc';
    case SaoPaulo = 'sp';
    case Sergipe = 'se';
    case Tocantins = 'to';
    case Exterior = 'zz';

    /**
     * Human readable state name.
     */
    public function label(): string
    {
        return match ($this) {
            self::Acre => 'Acre',
            self::Alagoas => 'Alagoas',
            self::Amapa => 'Amapá',
            self::Amazonas => 'Amazonas',
            self::Bahia => 'Bahia',
            self::Ceara => 'Ceará',
            self::DistritoFederal => 'Distrito Federal',
            self::EspiritoSanto => 'Espírito Santo',
            self::Goias => 'Goiás',
            self::Maranhao => 'Maranhão',
            self::MatoGrosso => 'Mato Grosso',
            self::MatoGrossoDoSul => 'Mato Grosso do Sul',
            self::MinasGerais => 'Minas Gerais',
            self::Para => 'Pará',
            self::Paraiba => 'Paraíba',
            self::Parana => 'Paraná',
            self::Pernambuco => 'Pernambuco',
            self::Piaui => 'Piauí',
            self::RioDeJaneiro => 'Rio de Janeiro',
            self::RioGrandeDoNorte => 'Rio Grande do Norte',
            self::RioGrandeDoSul => 'Rio Grande do Sul',
            self::Rondonia => 'Rondônia',
            self::Roraima => 'Roraima',
            self::SantaCatarina => 'Santa Catarina',
            self::SaoPaulo => 'São Paulo',
            self::Sergipe => 'Sergipe',
            self::Tocantins => 'Tocantins',
            self::Exterior => 'Exterior',
        };
    }

    /**
     * Two letter abbreviation in upper case.
     */
    public function abbreviation(): string
    {
        return strtoupper($this->value);
    }

    /**
     * Prefix of the IBGE municipality codes of this state.
     */
    public function ibgePrefix(): ?string
    {
        return match ($this) {
            self::Rondonia => '11', self::Acre => '12', self::Amazonas => '13', self::Roraima => '14',
            self::Para => '15', self::Amapa => '16', self::Tocantins => '17', self::Maranhao => '21',
            self::Piaui => '22', self::Ceara => '23', self::RioGrandeDoNorte => '24', self::Paraiba => '25',
            self::Pernambuco => '26', self::Alagoas => '27', self::Sergipe => '28', self::Bahia => '29',
            self::MinasGerais => '31', self::EspiritoSanto => '32', self::RioDeJaneiro => '33',
            self::SaoPaulo => '35', self::Parana => '41', self::SantaCatarina => '42',
            self::RioGrandeDoSul => '43', self::MatoGrossoDoSul => '50', self::MatoGrosso => '51',
            self::Goias => '52', self::DistritoFederal => '53', self::Exterior => null,
        };
    }
}
