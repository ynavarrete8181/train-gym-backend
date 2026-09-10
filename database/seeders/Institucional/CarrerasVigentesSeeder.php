<?php

namespace Database\Seeders\Institucional;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CarrerasVigentesSeeder extends Seeder
{
    public function run(): void
    {
        $filas = [
            ['Administración de Empresas', 'Matriz - Manta', 'Matriz - Manta', true, 'Ciencias Administrativas, Contables y Comercio', 'Administración', '650413B01-P-1308'],
            ['Administración de Empresas', 'Sucre', 'Sucre', true, 'Ciencias Administrativas, Contables y Comercio', 'Administración', '650413B01-P-1314'],
            ['Administración de Empresas', 'El Carmen', 'El Carmen', true, 'Ciencias Administrativas, Contables y Comercio', 'Administración', '650413B01-P-1304'],
            ['Administración de Empresas', 'Santo Domingo', 'Santo Domingo', true, 'Ciencias Administrativas, Contables y Comercio', 'Administración', '650413B01-P-2301'],
            ['Agroindustria', 'Matriz - Manta', 'Matriz - Manta', false, 'Ciencias de la Vida y Tecnologías', 'Ingeniería, Industria y Construcción', '650721A01-P-1308'],
            ['Agronegocios', 'Matriz - Manta', 'Matriz - Manta', true, 'Ciencias de la Vida y Tecnologías', 'Administración', '650413D01-P-1308'],
            ['Agronegocios', 'Chone', 'Chone', true, 'Ciencias Administrativas, Contables y Comercio', 'Administración', '650413D01-P-1303'],
            ['Agronegocios', 'El Carmen', 'El Carmen', true, 'Ciencias Administrativas, Contables y Comercio', 'Administración', '650413D01-P-1304'],
            ['Agronegocios', 'San Isidro', 'Sucre', true, 'Ciencias de la Vida y Tecnologías', 'Administración', '650413D01-P-1314'],
            ['Agropecuaria', 'Matriz - Manta', 'Matriz - Manta', true, 'Ciencias de la Vida y Tecnologías', 'Agricultura, Silvicultura, Pesca y Veterinaria', '650811B01-P-1308'],
            ['Agropecuaria', 'Chone', 'Chone', true, 'Ciencias de la Vida y Tecnologías', 'Agricultura, Silvicultura, Pesca y Veterinaria', '650811B-P-02'],
            ['Agropecuaria', 'El Carmen', 'El Carmen', true, 'Ciencias de la Vida y Tecnologías', 'Agricultura, Silvicultura, Pesca y Veterinaria', '650811B-P-03'],
            ['Agropecuaria', 'Pedernales', 'Pedernales', true, 'Ciencias de la Vida y Tecnologías', 'Agricultura, Silvicultura, Pesca y Veterinaria', '650811B01-P-1317'],
            ['Agropecuaria', 'Santa Ana', 'Santa Ana', true, 'Ciencias de la Vida y Tecnologías', 'Agricultura, Silvicultura, Pesca y Veterinaria', '650811B01-P-1308'],
            ['Alimentos', 'Matriz - Manta', 'Matriz - Manta', true, 'Ingeniería, Industria y Construcción', 'Ingeniería, Industria y Construcción', '650721B01-P-1308'],
            ['Alimentos', 'Chone', 'Chone', true, 'Ingeniería, Industria y Construcción', 'Ingeniería, Industria y Construcción', '650721B01-P-1303'],
            ['Alimentos', 'El Carmen', 'El Carmen', false, 'Ingeniería, Industria y Construcción', 'Ingeniería, Industria y Construcción', '650721B01-P-1304'],
            ['Arquitectura', 'Matriz - Manta', 'Matriz - Manta', true, 'Ingeniería, Industria y Construcción', 'Ingeniería, Industria y Construcción', '6507381A01-P-1308'],
            ['Arquitectura', 'Santo Domingo', 'Santo Domingo', true, 'Ingeniería, Industria y Construcción', 'Ingeniería, Industria y Construcción', '6507381A01-P-2301'],
            ['Artes Plásticas', 'Matriz - Manta', 'Matriz - Manta', true, 'Artes, Humanidades y Patrimonio', 'Artes y Humanidades', '650213A01-P-1308'],
            ['Auditoría y Control de Gestión', 'Matriz - Manta', 'Matriz - Manta', true, 'Ciencias Administrativas, Contables y Comercio', 'Administración', '650411B01-P-1308'],
            ['Auditoría y Control de Gestión', 'El Carmen', 'El Carmen', true, 'Ciencias Administrativas, Contables y Comercio', 'Administración', '650411B01-P-1304'],
            ['Biología', 'Matriz - Manta', 'Matriz - Manta', true, 'Ciencias de la Vida y Tecnologías', 'Ciencias Naturales, Matemáticas y Estadística', '650511A01-P-1308'],
            ['Comercio Exterior', 'Matriz - Manta', 'Matriz - Manta', true, 'Ciencias Administrativas, Contables y Comercio', 'Administración', '650416B02-P-1308'],
            ['Contabilidad y Auditoría', 'Matriz - Manta', 'Matriz - Manta', true, 'Ciencias Administrativas, Contables y Comercio', 'Administración', '650411A01-P-1308'],
            ['Contabilidad y Auditoría', 'Sucre', 'Sucre', true, 'Ciencias Administrativas, Contables y Comercio', 'Administración', '650411A-P-01'],
            ['Contabilidad y Auditoría', 'El Carmen', 'El Carmen', true, 'Ciencias Administrativas, Contables y Comercio', 'Administración', '650411A01-P-1304'],
            ['Contabilidad y Auditoría', 'Santo Domingo', 'Santo Domingo', true, 'Ciencias Administrativas, Contables y Comercio', 'Administración', '650411A01-P-2301'],
            ['Criminología y Ciencias Forenses', 'Matriz - Manta', 'Matriz - Manta', true, 'Ciencias Sociales, Derecho y Bienestar', 'Ciencias Sociales, Periodismo, Información y Derecho', '650312D01-P-1308'],
            ['Derecho', 'Matriz - Manta', 'Matriz - Manta', true, 'Ciencias Sociales, Derecho y Bienestar', 'Ciencias Sociales, Periodismo, Información y Derecho', '650421A01-P-1308'],
            ['Derecho', 'Chone', 'Chone', true, 'Ciencias Sociales, Derecho y Bienestar', 'Ciencias Sociales, Periodismo, Información y Derecho', '650421A01-P-1303'],
            ['Derecho', 'El Carmen', 'El Carmen', true, 'Ciencias Sociales, Derecho y Bienestar', 'Ciencias Sociales, Periodismo, Información y Derecho', '650421A01-P-1304'],
            ['Derecho', 'Pedernales', 'Pedernales', true, 'Ciencias Sociales, Derecho y Bienestar', 'Ciencias Sociales, Periodismo, Información y Derecho', '650421A01-P-1317'],
            ['Derecho', 'Santo Domingo', 'Santo Domingo', true, 'Ciencias Sociales, Derecho y Bienestar', 'Ciencias Sociales, Periodismo, Información y Derecho', '650421A01-P-2301'],
            ['Diseño Gráfico', 'Matriz - Manta', 'Matriz - Manta', true, 'Artes, Humanidades y Patrimonio', 'Artes y Humanidades', '650214A01-P-1308'],
            ['Economía', 'Matriz - Manta', 'Matriz - Manta', true, 'Ciencias Administrativas, Contables y Comercio', 'Administración', '650314A01-P-1308'],
            ['Economía', 'El Carmen', 'El Carmen', false, 'Ciencias Administrativas, Contables y Comercio', 'Administración', '650314A01-P-1304'],
            ['Educación Básica', 'Matriz - Manta', 'Matriz - Manta', true, 'Educación y Turismo', 'Educación', '650113B01-P-1308'],
            ['Educación Básica', 'Chone', 'Chone', true, 'Educación y Turismo', 'Educación', '650113B01-P-1303'],
            ['Educación Básica', 'El Carmen', 'El Carmen', true, 'Educación y Turismo', 'Educación', '650113B01-P-1304'],
            ['Educación Básica', 'Pedernales', 'Pedernales', true, 'Educación y Turismo', 'Educación', '650113B01-P-1317'],
            ['Educación Básica', 'Santo Domingo', 'Santo Domingo', true, 'Educación y Turismo', 'Educación', '650113B01-P-2301'],
            ['Educación Especial', 'Matriz - Manta', 'Matriz - Manta', true, 'Educación y Turismo', 'Educación', '650113C01-P-1308'],
            ['Educación Inicial', 'Matriz - Manta', 'Matriz - Manta', true, 'Educación y Turismo', 'Educación', '650112A01-P-1308'],
            ['Educación Inicial', 'Chone', 'Chone', true, 'Educación y Turismo', 'Educación', '650112A01-P-1303'],
            ['Educación Inicial', 'El Carmen', 'El Carmen', true, 'Educación y Turismo', 'Educación', '650112A01-P-1304'],
            ['Educación Inicial', 'Pedernales', 'Pedernales', true, 'Educación y Turismo', 'Educación', '650112A01-P-1317'],
            ['Educación Inicial', 'Santo Domingo', 'Santo Domingo', true, 'Educación y Turismo', 'Educación', '650112A01-P-2301'],
            ['Electromecánica', 'Chone', 'Chone', true, 'Ingeniería, Industria y Construcción', 'Ingeniería, Industria y Construcción', '650714A01-P-1303'],
            ['Electromecánica', 'El Carmen', 'El Carmen', true, 'Ingeniería, Industria y Construcción', 'Ingeniería, Industria y Construcción', '650714A01-P-1304'],
            ['Electromecánica', 'Flavio Alfaro', 'Chone', true, 'Ingeniería, Industria y Construcción', 'Ingeniería, Industria y Construcción', '650714A01-P-1303'],
            ['Enfermería', 'Matriz - Manta', 'Matriz - Manta', true, 'Ciencias de la Salud', 'Salud y Bienestar', '650912A01-P-1308'],
            ['Enfermería', 'Chone', 'Chone', true, 'Ciencias de la Salud', 'Salud y Bienestar', '650912A01-P-1303'],
            ['Enfermería', 'Pichincha', 'Chone', true, 'Ciencias de la Salud', 'Salud y Bienestar', '650912A01-P-1303'],
            ['Enfermería', 'Santo Domingo', 'Santo Domingo', true, 'Ciencias de la Salud', 'Salud y Bienestar', '650912A01-P-2301'],
            ['Fisioterapia', 'Matriz - Manta', 'Matriz - Manta', true, 'Ciencias de la Salud', 'Salud y Bienestar', '650915A01-P-1308'],
            ['Gastronomía', 'Matriz - Manta', 'Matriz - Manta', true, 'Educación y Turismo', 'Servicios', '6501013A01-P-1308'],
            ['Gestión de la Información Gerencial', 'Matriz - Manta', 'Matriz - Manta', true, 'Ciencias Administrativas, Contables y Comercio', 'Administración', '650413E01-P-1308'],
            ['Gestión de Talento Humano', 'Matriz - Manta', 'Matriz - Manta', true, 'Ciencias Administrativas, Contables y Comercio', 'Administración', '650413F01-P-1308'],
            ['Gestión Social y Desarrollo', 'Matriz - Manta', 'Matriz - Manta', true, 'Ciencias Sociales, Derecho y Bienestar', 'Ciencias Sociales, Periodismo, Información y Derecho', '650312B01-P-1308'],
            ['Hospitalidad y Hotelería', 'Matriz - Manta', 'Matriz - Manta', true, 'Educación y Turismo', 'Servicios', '6501013B01-P-1308'],
            ['Ingeniería Ambiental', 'Matriz - Manta', 'Matriz - Manta', true, 'Ingeniería, Industria y Construcción', 'Ingeniería, Industria y Construcción', '650712A01-P-1308'],
            ['Ingeniería Civil', 'Matriz - Manta', 'Matriz - Manta', true, 'Ingeniería, Industria y Construcción', 'Ingeniería, Industria y Construcción', '650732A01-P-1308'],
            ['Ingeniería Civil', 'Chone', 'Chone', true, 'Ingeniería, Industria y Construcción', 'Ingeniería, Industria y Construcción', '650732A01-P-1303'],
            ['Ingeniería Civil', 'Santo Domingo', 'Santo Domingo', true, 'Ingeniería, Industria y Construcción', 'Ingeniería, Industria y Construcción', '650732A01-P-2301'],
            ['Ingeniería de Software', 'Matriz - Manta', 'Matriz - Manta', true, 'Ciencias de la Vida y Tecnologías', 'Tecnologías de la Información y la Comunicación', '650612A01-P-1308'],
            ['Ingeniería de Software', 'Chone', 'Chone', true, 'Ciencias de la Vida y Tecnologías', 'Tecnologías de la Información y la Comunicación', '650612A01-P-1303'],
            ['Ingeniería de Software', 'El Carmen', 'El Carmen', true, 'Ciencias de la Vida y Tecnologías', 'Tecnologías de la Información y la Comunicación', '650612A01-P-1304'],
            ['Ingeniería de Software', 'Santo Domingo', 'Santo Domingo', true, 'Ciencias de la Vida y Tecnologías', 'Tecnologías de la Información y la Comunicación', '650612A01-P-2301'],
            ['Ingeniería en Alimentos', 'Matriz - Manta', 'Matriz - Manta', false, 'Ingeniería, Industria y Construcción', 'Ingeniería, Industria y Construcción', '650721C01-P-1308'],
            ['Ingeniería Industrial', 'Matriz - Manta', 'Matriz - Manta', true, 'Ingeniería, Industria y Construcción', 'Ingeniería, Industria y Construcción', '650725A01-P-1308'],
            ['Ingeniería Industrial', 'El Carmen', 'El Carmen', true, 'Ingeniería, Industria y Construcción', 'Ingeniería, Industria y Construcción', '650725A01-P-1304'],
            ['Ingeniería Industrial', 'Santo Domingo', 'Santo Domingo', true, 'Ingeniería, Industria y Construcción', 'Ingeniería, Industria y Construcción', '650725A01-P-2301'],
            ['Logística y Transporte', 'Matriz - Manta', 'Matriz - Manta', true, 'Ciencias Administrativas, Contables y Comercio', 'Servicios', '6501041A01-P-1308'],
            ['Marketing', 'Matriz - Manta', 'Matriz - Manta', true, 'Ciencias Administrativas, Contables y Comercio', 'Administración', '650414A01-P-1308'],
            ['Marketing', 'El Carmen', 'El Carmen', true, 'Ciencias Administrativas, Contables y Comercio', 'Administración', '650414A01-P-1304'],
            ['Medicina', 'Matriz - Manta', 'Matriz - Manta', true, 'Ciencias de la Salud', 'Salud y Bienestar', '650912B01-P-1308'],
            ['Nutrición y Dietética', 'Matriz - Manta', 'Matriz - Manta', true, 'Ciencias de la Salud', 'Salud y Bienestar', '650915B01-P-1308'],
            ['Odontología', 'Matriz - Manta', 'Matriz - Manta', true, 'Ciencias de la Salud', 'Salud y Bienestar', '650911A01-P-1308'],
            ['Pedagogía de la Actividad Física y Deporte', 'Matriz - Manta', 'Matriz - Manta', true, 'Educación y Turismo', 'Educación', '650114A01-P-1308'],
            ['Pedagogía de la Lengua y la Literatura', 'Matriz - Manta', 'Matriz - Manta', true, 'Educación y Turismo', 'Educación', '650113D01-P-1308'],
            ['Pedagogía de las Ciencias Experimentales', 'Matriz - Manta', 'Matriz - Manta', true, 'Educación y Turismo', 'Educación', '650113E01-P-1308'],
            ['Pedagogía de los Idiomas Nacionales y Extranjeros', 'Matriz - Manta', 'Matriz - Manta', true, 'Educación y Turismo', 'Educación', '650113F01-P-1308'],
            ['Pesca y Acuicultura', 'Matriz - Manta', 'Matriz - Manta', true, 'Ciencias de la Vida y Tecnologías', 'Agricultura, Silvicultura, Pesca y Veterinaria', '650831A01-P-1308'],
            ['Psicología', 'Matriz - Manta', 'Matriz - Manta', true, 'Ciencias Sociales, Derecho y Bienestar', 'Ciencias Sociales, Periodismo, Información y Derecho', '650313A01-P-1308'],
            ['Psicología', 'Chone', 'Chone', true, 'Ciencias Sociales, Derecho y Bienestar', 'Ciencias Sociales, Periodismo, Información y Derecho', '650313A01-P-1303'],
            ['Psicología', 'El Carmen', 'El Carmen', true, 'Ciencias Sociales, Derecho y Bienestar', 'Ciencias Sociales, Periodismo, Información y Derecho', '650313A01-P-1304'],
            ['Psicología', 'Santo Domingo', 'Santo Domingo', true, 'Ciencias Sociales, Derecho y Bienestar', 'Ciencias Sociales, Periodismo, Información y Derecho', '650313A01-P-2301'],
            ['Publicidad', 'Matriz - Manta', 'Matriz - Manta', true, 'Ciencias Administrativas, Contables y Comercio', 'Administración', '650414B01-P-1308'],
            ['Recursos Naturales Renovables', 'Matriz - Manta', 'Matriz - Manta', false, 'Ciencias de la Vida y Tecnologías', 'Agricultura, Silvicultura, Pesca y Veterinaria', '650822A01-P-1308'],
            ['Sociología', 'Matriz - Manta', 'Matriz - Manta', true, 'Ciencias Sociales, Derecho y Bienestar', 'Ciencias Sociales, Periodismo, Información y Derecho', '650312A01-P-1308'],
            ['Tecnologías de la Información', 'Matriz - Manta', 'Matriz - Manta', true, 'Ciencias de la Vida y Tecnologías', 'Tecnologías de la Información y la Comunicación', '650612B01-P-1308'],
            ['Tecnologías de la Información', 'Chone', 'Chone', true, 'Ciencias de la Vida y Tecnologías', 'Tecnologías de la Información y la Comunicación', '650612B01-P-1303'],
            ['Tecnologías de la Información', 'El Carmen', 'El Carmen', true, 'Ciencias de la Vida y Tecnologías', 'Tecnologías de la Información y la Comunicación', '650612B01-P-1304'],
            ['Tecnologías de la Información', 'Santo Domingo', 'Santo Domingo', true, 'Ciencias de la Vida y Tecnologías', 'Tecnologías de la Información y la Comunicación', '650612B01-P-2301'],
            ['Trabajo Social', 'Matriz - Manta', 'Matriz - Manta', true, 'Ciencias Sociales, Derecho y Bienestar', 'Ciencias Sociales, Periodismo, Información y Derecho', '650312C01-P-1308'],
            ['Trabajo Social', 'Chone', 'Chone', true, 'Ciencias Sociales, Derecho y Bienestar', 'Ciencias Sociales, Periodismo, Información y Derecho', '650312C01-P-1303'],
            ['Trabajo Social', 'El Carmen', 'El Carmen', true, 'Ciencias Sociales, Derecho y Bienestar', 'Ciencias Sociales, Periodismo, Información y Derecho', '650312C01-P-1304'],
            ['Trabajo Social', 'Santo Domingo', 'Santo Domingo', true, 'Ciencias Sociales, Derecho y Bienestar', 'Ciencias Sociales, Periodismo, Información y Derecho', '650312C01-P-2301'],
            ['Turismo', 'Matriz - Manta', 'Matriz - Manta', true, 'Educación y Turismo', 'Servicios', '6501015A01-P-1308'],
            ['Turismo', 'Pedernales', 'Pedernales', true, 'Educación y Turismo', 'Servicios', '6501015A01-P-1317'],
            ['Turismo', 'Puerto López', 'Puerto López', true, 'Educación y Turismo', 'Servicios', '6501015A01-P-1309'],
            ['Turismo', 'Santo Domingo', 'Santo Domingo', true, 'Educación y Turismo', 'Servicios', '6501015A01-P-2301'],
            ['Veterinaria', 'Matriz - Manta', 'Matriz - Manta', true, 'Ciencias de la Vida y Tecnologías', 'Agricultura, Silvicultura, Pesca y Veterinaria', '650841A01-P-1308'],
            ['Veterinaria', 'Chone', 'Chone', true, 'Ciencias de la Vida y Tecnologías', 'Agricultura, Silvicultura, Pesca y Veterinaria', '650841A01-P-1303'],
            ['Veterinaria', 'El Carmen', 'El Carmen', true, 'Ciencias de la Vida y Tecnologías', 'Agricultura, Silvicultura, Pesca y Veterinaria', '650841A01-P-1304'],
            ['Veterinaria', 'Pedernales', 'Pedernales', true, 'Ciencias de la Vida y Tecnologías', 'Agricultura, Silvicultura, Pesca y Veterinaria', '650841A01-P-1317'],
            ['Arquitectura', 'Chone', 'Chone', false, 'Ingeniería, Industria y Construcción', 'Ingeniería, Industria y Construcción', '6507381A01-P-1303'],
            ['Comercio Exterior', 'El Carmen', 'El Carmen', false, 'Ciencias Administrativas, Contables y Comercio', 'Administración', '650416B02-P-1304'],
            ['Educación Básica', 'Junín', 'Junín', false, 'Educación y Turismo', 'Educación', '650113B01-P-1307'],
            ['Educación Inicial', 'Junín', 'Junín', false, 'Educación y Turismo', 'Educación', '650112A01-P-1307'],
            ['Enfermería', 'El Carmen', 'El Carmen', false, 'Ciencias de la Salud', 'Salud y Bienestar', '650912A01-P-1304'],
            ['Enfermería', 'Pedernales', 'Pedernales', false, 'Ciencias de la Salud', 'Salud y Bienestar', '650912A01-P-1317'],
            ['Ingeniería Civil', 'El Carmen', 'El Carmen', false, 'Ingeniería, Industria y Construcción', 'Ingeniería, Industria y Construcción', '650732A01-P-1304'],
            ['Ingeniería de Software', 'Pedernales', 'Pedernales', false, 'Ciencias de la Vida y Tecnologías', 'Tecnologías de la Información y la Comunicación', '650612A01-P-1317'],
            ['Marketing', 'Santo Domingo', 'Santo Domingo', false, 'Ciencias Administrativas, Contables y Comercio', 'Administración', '650414A01-P-2301'],
            ['Psicología', 'Pedernales', 'Pedernales', false, 'Ciencias Sociales, Derecho y Bienestar', 'Ciencias Sociales, Periodismo, Información y Derecho', '650313A01-P-1317'],
            ['Tecnologías de la Información', 'Pedernales', 'Pedernales', false, 'Ciencias de la Vida y Tecnologías', 'Tecnologías de la Información y la Comunicación', '650612B01-P-1317'],
            ['Trabajo Social', 'Pedernales', 'Pedernales', false, 'Ciencias Sociales, Derecho y Bienestar', 'Ciencias Sociales, Periodismo, Información y Derecho', '650312C01-P-1317'],
            ['Turismo', 'El Carmen', 'El Carmen', false, 'Educación y Turismo', 'Servicios', '6501015A01-P-1304'],
            ['Mecánica Automotriz', 'Matriz - Manta', 'Matriz - Manta', true, 'Unidad Académica de Formación Técnica y Tecnológica', 'Ingeniería, Industria y Construcción', '650716A01-T-1308'],
            ['Electricidad', 'Matriz - Manta', 'Matriz - Manta', true, 'Unidad Académica de Formación Técnica y Tecnológica', 'Ingeniería, Industria y Construcción', '650713A01-T-1308'],
            ['Desarrollo de Software', 'Matriz - Manta', 'Matriz - Manta', true, 'Unidad Académica de Formación Técnica y Tecnológica', 'Tecnologías de la Información y la Comunicación', '650612C01-T-1308'],
            ['Redes y Telecomunicaciones', 'Matriz - Manta', 'Matriz - Manta', true, 'Unidad Académica de Formación Técnica y Tecnológica', 'Tecnologías de la Información y la Comunicación', '650611A01-T-1308'],
            ['Procesamiento de Alimentos', 'Matriz - Manta', 'Matriz - Manta', true, 'Unidad Académica de Formación Técnica y Tecnológica', 'Ingeniería, Industria y Construcción', '650721D01-T-1308'],
            ['Seguridad y Prevención de Riesgos Laborales', 'Matriz - Manta', 'Matriz - Manta', true, 'Unidad Académica de Formación Técnica y Tecnológica', 'Servicios', '6501022A01-T-1308'],
            ['Producción Agropecuaria', 'Matriz - Manta', 'Matriz - Manta', true, 'Unidad Académica de Formación Técnica y Tecnológica', 'Agricultura, Silvicultura, Pesca y Veterinaria', '650811C01-T-1308'],
            ['Administración', 'Matriz - Manta', 'Matriz - Manta', true, 'Unidad Académica de Formación Técnica y Tecnológica', 'Administración', '650413G01-T-1308'],
            ['Contabilidad', 'Matriz - Manta', 'Matriz - Manta', true, 'Unidad Académica de Formación Técnica y Tecnológica', 'Administración', '650411C01-T-1308'],
            ['Ciencias Políticas y Relaciones Internacionales', 'Matriz - Manta', 'Matriz - Manta', true, 'Ciencias Sociales, Derecho y Bienestar', 'Ciencias Sociales, Periodismo, Información y Derecho', '650312F01-H-1308'],
            ['Gestión Pública y Desarrollo', 'Matriz - Manta', 'Matriz - Manta', false, 'Ciencias Sociales, Derecho y Bienestar', 'Ciencias Sociales, Periodismo, Información y Derecho', '650312E01-S-1308'],
        ];

        DB::transaction(function () use ($filas): void {
            foreach ($filas as [$carrera, $sedeUleam, , $activo, $estructura, $campoAmplio, $codigoCes]) {
                $sedeCodigo = 'SEDE_'.$this->codigo($sedeUleam);
                $idSede = DB::table('institucional.sedes')->whereRaw('LOWER(nombre) = ?', [mb_strtolower($sedeUleam)])->value('id_sede');
                if (! $idSede) {
                    $idSede = DB::table('institucional.sedes')->insertGetId(['codigo' => $sedeCodigo, 'nombre' => $sedeUleam, 'activo' => true, 'created_at' => now(), 'updated_at' => now()], 'id_sede');
                }

                $idUnidad = DB::table('institucional.unidades')->whereRaw('LOWER(nombre) = ?', [mb_strtolower($estructura)])->where('tipo', 'FACULTAD')->value('id_unidad');
                if (! $idUnidad) {
                    $codigoUnidad = 'FACULTAD_'.$this->codigo($estructura);
                    if (DB::table('institucional.unidades')->where('codigo', $codigoUnidad)->exists()) $codigoUnidad .= '_'.substr(sha1($estructura), 0, 6);
                    $idUnidad = DB::table('institucional.unidades')->insertGetId(['codigo' => $codigoUnidad, 'nombre' => $estructura, 'tipo' => 'FACULTAD', 'activo' => true, 'created_at' => now(), 'updated_at' => now()], 'id_unidad');
                }

                $codigoRelacion = 'FACULTAD_'.$this->codigo(str_replace('SEDE_', '', $sedeCodigo)).'_'.$this->codigo($estructura);
                $idSedeUnidad = DB::table('institucional.sede_unidad')->where('id_sede', $idSede)->where('id_unidad', $idUnidad)->value('id');
                if (! $idSedeUnidad) {
                    $idSedeUnidad = DB::table('institucional.sede_unidad')->insertGetId(['id_sede' => $idSede, 'id_unidad' => $idUnidad, 'codigo' => substr($codigoRelacion, 0, 200), 'activo' => true, 'created_at' => now(), 'updated_at' => now()]);
                } else {
                    DB::table('institucional.sede_unidad')->where('id', $idSedeUnidad)->update(['codigo' => substr($codigoRelacion, 0, 200), 'activo' => true, 'updated_at' => now()]);
                }

                $idCampoAmplio = DB::table('institucional.campos_amplios')->whereRaw('LOWER(nombre) = ?', [mb_strtolower($campoAmplio)])->value('id_campo_amplio');
                if (! $idCampoAmplio) {
                    $idCampoAmplio = DB::table('institucional.campos_amplios')->insertGetId(['codigo' => $this->codigo($campoAmplio), 'nombre' => $campoAmplio, 'activo' => true, 'created_at' => now(), 'updated_at' => now()], 'id_campo_amplio');
                }

                $codigoCarrera = substr('CARRERA_'.$this->codigo(str_replace('SEDE_', '', $sedeCodigo)).'_'.$this->codigo($carrera), 0, 200);
                DB::table('institucional.carreras_areas')->updateOrInsert(
                    ['id_sede_unidad' => $idSedeUnidad, 'nombre' => $carrera],
                    ['id_unidad' => $idUnidad, 'codigo' => $codigoCarrera, 'codigo_ces' => $codigoCes, 'tipo' => 'CARRERA', 'id_campo_amplio' => $idCampoAmplio, 'activo' => $activo, 'updated_at' => now(), 'created_at' => now()]
                );
            }
        });
    }

    private function codigo(string $valor): string
    {
        return Str::of(Str::ascii($valor))->upper()->replaceMatches('/[^A-Z0-9]+/', '_')->trim('_')->toString();
    }
}
