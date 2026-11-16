const pageNames = {
    dashboard: ['Resumen general', 'Buen día, equipo.'],
    turnos: ['Turnos', 'Gestión de turnos.'],
    pacientes: ['Pacientes', 'Personas en seguimiento.'],
    profesionales: ['Profesionales', 'El equipo que acompaña.'],
    especialidades: ['Especialidades', 'Áreas de atención.'],
    actividad: ['Registro de actividad', 'Trazabilidad del sistema.']
};
const toast = document.getElementById('toast');
let toastTimer;
function note(message) {
    toast.textContent = message;
    toast.classList.add('show');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => toast.classList.remove('show'), 3000);
}

function go(page) {
    document.querySelectorAll('.page').forEach(item => item.classList.toggle('active', item.id === page));
    document.querySelectorAll('.nav[data-page]').forEach(item => item.classList.toggle('active', item.dataset.page === page));
    document.getElementById('crumb').textContent = pageNames[page][0];
    document.getElementById('title').textContent = pageNames[page][1];
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

document.querySelectorAll('[data-page], [data-go]').forEach(button => {
button.addEventListener('click', () => go(button.dataset.page || button.dataset.go));
});
function closeAccount() {
}

document.addEventListener('keydown',e=>{if(e.key==='Escape'){closeNotifications();closeModal()}});
document.getElementById('sidebarAccount').onclick=()=> {
    openModal({title:'Cuenta del administrador',action:'__info',submit:'Cerrar',fields:`<div class="modal-field"><label>Usuario autenticado</label><input value="${safe(document.body.dataset.adminUser||'Administración')}" readonly></div><p class="muted">Desde aquí podés consultar tu usuario, cambiar la apariencia y cerrar tu sesión.</p><div class="modal-actions" style="justify-content:flex-start"><button class="btn secondary" type="button" id="accountThemeButton">Cambiar tema claro / oscuro</button><button class="btn secondary" type="button" id="accountLogoutButton" style="color:#b84a40;border-color:#e6c7c2">Cerrar sesión</button></div>`});
    document.getElementById('modalSubmit').hidden=true;
    document.getElementById('modalCancel').textContent='Cerrar';
    document.getElementById('accountThemeButton').onclick=()=> {
        const next=document.documentElement.dataset.theme==='dark'?'light':'dark';
        applyAdminTheme(next);
        note(next==='dark'?'Vista oscura activada.':'Vista clara activada.')
    }
    ;
    document.getElementById('accountLogoutButton').onclick=()=>document.querySelector('#logoutForm button[name="salir"]').click()
};
function applyAdminTheme(theme) {
    document.documentElement.dataset.theme=theme;
    document.documentElement.style.colorScheme=theme;
    try {
        localStorage.setItem('cms-admin-theme',theme)
    }

    catch(e) {
    }

}

try {
    applyAdminTheme(localStorage.getItem('cms-admin-theme')||'light')
}

catch(e) {
    applyAdminTheme('light')
}

document.getElementById('settings').onclick=()=>document.getElementById('sidebarAccount').click();
const notificationPanel=document.getElementById('notificationPanel'), notificationBackdrop=document.getElementById('notificationBackdrop');
function openNotifications() {
    notificationPanel.classList.add('open');
    notificationBackdrop.classList.add('open');
    notificationPanel.setAttribute('aria-hidden','false');
    loadAdminData()
}

function closeNotifications() {
    notificationPanel?.classList.remove('open');
    notificationBackdrop?.classList.remove('open');
    notificationPanel?.setAttribute('aria-hidden','true')
}

document.getElementById('notify').onclick=openNotifications;
document.getElementById('closeNotifications').onclick=closeNotifications;
notificationBackdrop.onclick=closeNotifications;
document.getElementById('markAllRead').onclick=async()=> {
    try {
        await api('marcar_todas_notificaciones',{});
        await loadAdminData()
    }

    catch(e) {
        note(e.message)
    }

};
const searchInput=document.getElementById('search');
const searchPlaceholders= {
    dashboard:'Buscar en turnos recientes…',turnos:'Buscar paciente, DNI, profesional, fecha o estado…',pacientes:'Buscar nombre, DNI, teléfono o correo…',profesionales:'Buscar profesional o especialidad…',especialidades:'Buscar especialidad…',actividad:'Buscar actividad…'
};
function applyContextSearch() {
    const page=document.querySelector('.page.active');
    if(!page)return;
    const query=searchInput.value.trim().toLocaleLowerCase('es-AR');
    const target=page.id==='dashboard'?page.querySelectorAll('#recent tbody tr'):page.id==='turnos'?page.querySelectorAll('#appointmentsBody tr'):page.id==='pacientes'?page.querySelectorAll('#patientsBody tr'):page.id==='profesionales'?page.querySelectorAll('#professionalsList .activityrow'):page.id==='especialidades'?page.querySelectorAll('#specialtiesList .mini-card'):page.id==='actividad'?page.querySelectorAll('#actividad .activityrow'):[];
    let visible=0;
    target.forEach(row=>{const searchMatch=!query||(row.textContent+' '+(row.dataset.search||'')).toLocaleLowerCase('es-AR').includes(query);const selectedStatus=page.id==='turnos'?document.getElementById('filter').value:'';const rowStatus=row.querySelector('.status')?.textContent.trim().replace(' (registro anterior)','');const statusMatch=!selectedStatus||rowStatus===selectedStatus;const match=searchMatch&&statusMatch;row.style.display=match?'':'none';if(match&&row.querySelector('td, b'))visible++;});
    let empty=page.querySelector('.context-search-empty');
    if(!empty) {
        empty=document.createElement('div');
        empty.className='simple-note context-search-empty';
        empty.hidden=true;
        const container=page.id==='dashboard'?page.querySelector('#recent').parentElement:page.id==='turnos'?page.querySelector('#allturns').parentElement:page.id==='pacientes'?page.querySelector('#patientsBody').closest('.tablewrap'):page.id==='profesionales'?page.querySelector('#professionalsList'):page.id==='especialidades'?page.querySelector('#specialtiesList'):page.querySelector('#actividad .activity');
        container?.appendChild(empty);
    }

    if(empty) {
        empty.hidden=!(query&&visible===0);
        empty.textContent='No se encontraron coincidencias en esta sección.';
    }

}

searchInput.addEventListener('input',applyContextSearch);
const originalGo=go;
go=function(page) {
    originalGo(page);
    searchInput.placeholder=searchPlaceholders[page]||'Buscar en esta sección…';
    applyContextSearch();
};
document.getElementById('filter').onchange = applyContextSearch;
async function api(action, data = null) {
    const options = data === null ? {
    }

    : {
        method: 'POST', headers: {
            'Content-Type': 'application/json'
        }
        , body: JSON.stringify(data)
    }
    ;
    const response = await fetch('api.php?action=' + encodeURIComponent(action), { ...options, cache: 'no-store', credentials: 'same-origin' });
    const raw = await response.text();
    let result;
    try {
        result = JSON.parse(raw);
    }

    catch (_) {
        const detail = raw.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 220);
        throw new Error('El servidor no devolvió una respuesta válida. Revisá la conexión a MySQL, las tablas y las migraciones. ' + (detail || ''));
    }

    if (!response.ok) throw new Error(result.error || 'No se pudo completar la operación.');
    return result;
}

function safe(value) {
    return String(value ?? '').replace(/[&<>"']/g, character => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
  }[character]));
}
function statusClass(status) {
  return { Pendiente: 'wait', Confirmado: 'ok', Cancelado: 'cancel', Atendido: 'ok' }[status] || 'wait';
}
function statusLabel(status){return status==='Pendiente'?'Pendiente (registro anterior)':status;}
async function loadAdminData() {
  try {
    const data = await api('admin_data');
    document.getElementById('metricToday').textContent = data.stats.today;
    document.getElementById('metricPending').textContent = data.stats.pending;
    document.getElementById('metricPatients').textContent = data.stats.patients;
    document.getElementById('metricProfessionals').textContent = data.stats.professionals;
    const appointmentRows = data.appointments.map(item => `<tr data-search="${safe(item.dni_paciente||'')}">
      <td>${safe(item.paciente)}</td><td>${safe(item.especialidad)}</td><td>${safe(item.profesional || 'Por asignar')}</td>
      <td>${safe(item.fecha)} · ${safe(item.hora)}</td><td><span class="status ${statusClass(item.estado)}">${safe(statusLabel(item.estado))}</span></td>
      <td><select class="search turn-status" data-id="${item.id}" aria-label="Estado del turno">${item.estado==='Pendiente'?'<option selected disabled value="Pendiente">Pendiente (registro anterior)</option>':''}<option ${item.estado==='Confirmado'?'selected':''}>Confirmado</option><option disabled ${item.estado==='Cancelado'?'selected':''}>Cancelado (canceló el paciente)</option><option ${item.estado==='Atendido'?'selected':''}>Atendido</option></select></td></tr>`).join('');
    document.getElementById('appointmentsBody').innerHTML = appointmentRows || '<tr><td colspan="6">Todavía no hay turnos registrados.</td></tr>';
    const recent = data.appointments.slice(0, 5).map(item => `<tr><td><div class="person"><span class="mini">${safe(item.iniciales)}</span><span><b>${safe(item.paciente)}</b><small>Solicitud registrada</small></span></div></td><td>${safe(item.especialidad)}</td><td>${safe(item.fecha)} · ${safe(item.hora)}</td><td><span class="status ${statusClass(item.estado)}">${safe(statusLabel(item.estado))}</span></td></tr>`).join('');
    document.querySelector('#recent tbody').innerHTML = recent || '<tr><td colspan="4">Todavía no hay solicitudes.</td></tr>';
    const todayAgenda=data.appointments.filter(item=>item.fecha===new Date().toLocaleDateString('es-AR')).slice(0,8);document.getElementById('todayAgenda').innerHTML=todayAgenda.map(item=>`<div class="event"><time>${safe(item.hora)}</time><div class="bar"></div><div><b>${safe(item.paciente)}</b><small>${safe(item.especialidad)} · ${safe(statusLabel(item.estado))}</small></div></div>`).join('')||'<div class="simple-note">No hay turnos registrados para hoy.</div>';
    document.getElementById('patientsBody').innerHTML = data.patients.map(item => `<tr data-search="${safe([item.dni,item.telefono,item.email].join(' '))}"><td>${safe(item.nombre)} ${safe(item.apellido)}</td><td>••••${safe(item.dni.slice(-2))}</td><td>${safe(item.email || item.telefono || 'Sin contacto')}</td><td>${safe(item.obra_social==='Otras'?(item.obra_social_otra||'Otras'):(item.obra_social||'Sin informar'))}</td><td>${safe(item.ultimo_turno || 'Sin turnos')}</td><td><div class="table-actions"><button class="textbtn edit-patient" data-id="${item.id}">Editar</button><button class="textbtn delete-patient" data-id="${item.id}">Eliminar</button>${item.ultimo_turno_id ? `<select class="search patient-turn-status" data-id="${item.ultimo_turno_id}" aria-label="Estado del último turno">${item.estado_turno==='Pendiente'?'<option selected disabled value="Pendiente">Pendiente (registro anterior)</option>':''}<option ${item.estado_turno==='Confirmado'?'selected':''}>Confirmado</option><option disabled ${item.estado_turno==='Cancelado'?'selected':''}>Cancelado (paciente)</option><option ${item.estado_turno==='Atendido'?'selected':''}>Atendido</option></select>` : ''}</div></td></tr>`).join('') || '<tr><td colspan="6">Todavía no hay pacientes registrados.</td></tr>';
    document.getElementById('professionalsList').innerHTML = data.professionals.map(item => `<div class="activityrow"><div class="mini">${safe(item.iniciales)}</div><div style="flex:1"><b>${safe(item.nombre)}</b><p>${safe(item.especialidad)} · ${safe(item.agenda||'Agenda no configurada')}</p></div><div class="pro-actions"><button class="textbtn edit-professional" data-id="${item.id}">Editar agenda</button><button class="textbtn delete-professional" data-id="${item.id}">Eliminar</button></div></div>`).join('') || '<p>No hay profesionales cargados.</p>';
    document.getElementById('specialtiesList').innerHTML = data.specialties.map(item => `<div class="mini-card"><span>＋</span><b>${safe(item.nombre)}</b><p>${safe(item.descripcion || 'Área de atención')}</p><div class="table-actions"><button class="textbtn edit-specialty" data-id="${item.id}">Editar</button><button class="textbtn delete-specialty" data-id="${item.id}">Eliminar</button></div></div>`).join('') || '<p>No hay especialidades cargadas.</p>';
    const activityPanel = document.querySelector('#actividad .activity');
    if (activityPanel) activityPanel.innerHTML = data.activity.map(item => `<div class="activityrow"><span class="activityicon">✓</span><div><b>${safe(item.descripcion)}</b><p>${safe(item.fecha)}</p></div></div>`).join('') || '<div class="simple-note">Todavía no hay actividad registrada.</div>';
    applyContextSearch();
    document.querySelectorAll('.turn-status, .patient-turn-status').forEach(select => select.onchange = async () => {
      const id = Number(select.dataset.id);
      try { await api('cambiar_estado', { id, estado: select.value }); note('Estado del turno actualizado.'); await loadAdminData(); }
      catch (error) { note(error.message); await loadAdminData(); }
    });
    document.querySelectorAll('.delete-professional').forEach(button => button.onclick = async () => {
      if (!confirm('¿Querés quitar este profesional del catálogo? Los turnos anteriores se conservarán.')) return;
      try { await api('eliminar_profesional', { id: Number(button.dataset.id) }); note('Profesional eliminado del catálogo.'); await loadAdminData(); }
      catch (error) { note(error.message); }
    });
    document.querySelectorAll('.delete-specialty').forEach(button => button.onclick = () => {
      const item=data.specialties.find(x=>Number(x.id)===Number(button.dataset.id));if(!item)return;
      confirmModal('Eliminar especialidad',`¿Confirmás desactivar “${item.nombre}”? Los turnos históricos se conservarán y la especialidad dejará de estar disponible para nuevas reservas.`,`Confirmar eliminación`,async()=>{try{await api('eliminar_especialidad',{id:Number(item.id)});note('Especialidad desactivada.');await loadAdminData()}catch(e){note(e.message)}});
    });
    currentAdminData=data;
    const unread=data.notifications.filter(n=>Number(n.leida)===0).length;const count=document.getElementById('noticeCount');count.hidden=!unread;count.textContent=unread>99?'99+':String(unread);document.getElementById('notificationSummary').textContent=`${unread} sin leer`;
    document.getElementById('notificationList').innerHTML=data.notifications.map(n=>`<article class="notification-item ${Number(n.leida)===0?'unread':''}"><p>${safe(n.mensaje)}</p><small>${safe(n.fecha)} · ${Number(n.leida)===0?'Sin leer':'Leída'}</small>${Number(n.leida)===0?`<div><button class="textbtn read-notification" data-id="${n.id}">Marcar como leída</button></div>`:''}${n.turno_id?`<div><button class="textbtn open-related-turn" data-id="${n.turno_id}">Ver turno</button></div>`:''}</article>`).join('')||'<div class="simple-note">No hay notificaciones todavía. Las solicitudes y cancelaciones reales aparecerán aquí.</div>';
    document.querySelectorAll('.read-notification').forEach(b=>b.onclick=async()=>{try{await api('marcar_notificacion',{id:Number(b.dataset.id)});await loadAdminData()}catch(e){note(e.message)}});
    document.querySelectorAll('.open-related-turn').forEach(b=>b.onclick=()=>{closeNotifications();go('turnos');const row=document.querySelector(`#appointmentsBody tr td select[data-id="${b.dataset.id}"]`)?.closest('tr');if(row){row.scrollIntoView({behavior:'smooth',block:'center'});row.style.outline='2px solid var(--gold)';setTimeout(()=>row.style.outline='',1600)}});
    document.querySelectorAll('.edit-patient').forEach(b=>b.onclick=()=>{const item=data.patients.find(x=>Number(x.id)===Number(b.dataset.id));if(!item)return;openModal({title:'Modificar paciente',action:'editar_paciente',id:Number(item.id),submit:'Guardar cambios',fields:patientFields(item)})});
    document.querySelectorAll('.delete-patient').forEach(b=>b.onclick=()=>{const item=data.patients.find(x=>Number(x.id)===Number(b.dataset.id));if(item)confirmModal('Eliminar paciente',`¿Confirmás eliminar a ${item.nombre} ${item.apellido}? Si tiene turnos asociados, el sistema conservará su historial y no permitirá borrarlo.`,`Confirmar eliminación`,async()=>{try{await api('eliminar_paciente',{id:Number(item.id)});note('Paciente eliminado.');await loadAdminData()}catch(e){note(e.message)}})});
    document.querySelectorAll('.edit-specialty').forEach(b=>b.onclick=()=>{const item=data.specialties.find(x=>Number(x.id)===Number(b.dataset.id));if(!item)return;openModal({title:'Modificar especialidad',action:'editar_especialidad',id:Number(item.id),submit:'Guardar cambios',fields:`<div class="modal-field"><label for="fNombre">Nombre *</label><input id="fNombre" name="nombre" required maxlength="100" value="${safe(item.nombre)}"></div><div class="modal-field"><label for="fDescripcion">Descripción</label><textarea id="fDescripcion" name="descripcion" maxlength="255">${safe(item.descripcion||'')}</textarea></div>`})});

  } catch (error) {
    note(error.message);
  }
}

const modalBackdrop=document.getElementById('modalBackdrop'), modalFields=document.getElementById('modalFields'), recordForm=document.getElementById('recordForm');
let modalConfig=null, currentAdminData=null;
function closeModal(){if(!modalBackdrop)return;modalBackdrop.classList.remove('open');modalBackdrop.setAttribute('aria-hidden','true');recordForm.reset();document.getElementById('modalError').classList.remove('show');document.getElementById('modalSubmit').hidden=false;document.getElementById('modalCancel').textContent='Cancelar';modalConfig=null}
function openModal(config){modalConfig=config;document.getElementById('modalTitle').textContent=config.title;document.getElementById('modalSubmit').textContent=config.submit||'Guardar';document.getElementById('modalError').classList.remove('show');modalFields.innerHTML=config.fields;modalBackdrop.classList.add('open');modalBackdrop.setAttribute('aria-hidden','false');const obra=modalFields.querySelector('[name=obra_social]'),otraWrap=modalFields.querySelector('#fOtraObraWrap'),otra=modalFields.querySelector('[name=obra_social_otra]');if(obra&&otraWrap&&otra){obra.onchange=()=>{const show=obra.value==='Otras';otraWrap.hidden=!show;otra.required=show;if(!show)otra.value='';};}const first=modalFields.querySelector('input,textarea,select');if(first)first.focus()}
document.getElementById('modalClose').onclick=closeModal;document.getElementById('modalCancel').onclick=closeModal;
modalBackdrop.addEventListener('click',e=>{if(e.target===modalBackdrop)closeModal()});
function patientFields(item={}){return `<div class="modal-grid"><div class="modal-field"><label for="fNombre">Nombre *</label><input id="fNombre" name="nombre" required maxlength="100" value="${safe(item.nombre||'')}"></div><div class="modal-field"><label for="fApellido">Apellido *</label><input id="fApellido" name="apellido" required maxlength="100" value="${safe(item.apellido||'')}"></div><div class="modal-field"><label for="fDni">DNI *</label><input id="fDni" name="dni" required inputmode="numeric" pattern="[0-9]{7,8}" minlength="7" maxlength="8" title="Solo números: 7 u 8 dígitos" value="${safe(item.dni||'')}"></div><div class="modal-field"><label for="fTelefono">Teléfono</label><input id="fTelefono" name="telefono" inputmode="numeric" pattern="[0-9]{8,15}" maxlength="15" title="Solo números, entre 8 y 15 dígitos" value="${safe(item.telefono||'')}"></div><div class="modal-field full"><label for="fEmail">Correo electrónico</label><input id="fEmail" name="email" type="email" maxlength="150" value="${safe(item.email||'')}"></div><div class="modal-field"><label for="fObraSocial">Obra social (opcional)</label><select id="fObraSocial" name="obra_social"><option value="" ${!item.obra_social?'selected':''}>Seleccioná una opción (opcional)</option>${['IOSCOR','OSECAC','ODFE','PAMI','Otras','Ninguna'].map(v=>`<option value="${v}" ${item.obra_social===v?'selected':''}>${v}</option>`).join('')}</select></div><div class="modal-field" id="fOtraObraWrap" ${item.obra_social==='Otras'?'':'hidden'}><label for="fOtraObra">Nombre de la obra social *</label><input id="fOtraObra" name="obra_social_otra" maxlength="150" value="${safe(item.obra_social_otra||'')}" ${item.obra_social==='Otras'?'required':''}></div></div>`}
function digitsOnly(input){input?.addEventListener('input',()=>{input.value=input.value.replace(/[^0-9]/g,'')})}
async function submitRecord(action,payload){try{document.getElementById('modalSubmit').disabled=true;document.getElementById('modalSubmit').textContent='Guardando…';await api(action,payload);closeModal();note('Cambios guardados correctamente.');await loadAdminData()}catch(error){const el=document.getElementById('modalError');el.textContent=error.message;el.classList.add('show')}finally{document.getElementById('modalSubmit').disabled=false;if(modalConfig)document.getElementById('modalSubmit').textContent=modalConfig.submit||'Guardar'}}
recordForm.addEventListener('submit',e=>{if(modalConfig?.action==='agregar_profesional'||modalConfig?.action==='__confirm')return;e.preventDefault();if(!modalConfig)return;const fd=new FormData(recordForm),data=Object.fromEntries(fd.entries());if(data.dni!==undefined&&!/^[0-9]{7,8}$/.test(data.dni)){const el=document.getElementById('modalError');el.textContent='El DNI debe contener solo 7 u 8 números.';el.classList.add('show');return}if(data.telefono&&!/^[0-9]{8,15}$/.test(data.telefono)){const el=document.getElementById('modalError');el.textContent='El teléfono debe contener solo números (8 a 15 dígitos).';el.classList.add('show');return}submitRecord(modalConfig.action,{...data,...(modalConfig.id?{id:modalConfig.id}:{})})});
modalFields.addEventListener('input',e=>{if((e.target.name==='dni'||e.target.name==='telefono') && e.target instanceof HTMLInputElement)e.target.value=e.target.value.replace(/[^0-9]/g,'').slice(0,e.target.name==='dni'?8:15)});
document.getElementById('newPatient').onclick=()=>openModal({title:'Agregar paciente',action:'agregar_paciente',submit:'Guardar paciente',fields:patientFields()});
document.getElementById('newSpec').onclick=()=>openModal({title:'Agregar especialidad',action:'agregar_especialidad',submit:'Guardar especialidad',fields:`<div class="modal-field"><label for="fNombre">Nombre *</label><input id="fNombre" name="nombre" required maxlength="100"></div><div class="modal-field"><label for="fDescripcion">Descripción</label><textarea id="fDescripcion" name="descripcion" maxlength="255"></textarea></div>`});
function scheduleRows(initial={}){const names=[['1','Lunes'],['2','Martes'],['3','Miércoles'],['4','Jueves'],['5','Viernes'],['6','Sábado'],['7','Domingo']];return `<div class="schedule-help muted">Activá los días de atención. En cada día podés configurar una franja continua o agregar una segunda franja opcional.</div>`+names.map(([v,n])=>{const ranges=initial[v]||[];const a=ranges[0]||{};const b=ranges[1]||{};const active=!!ranges.length;return `<div class="schedule-day schedule-day-multi"><div class="schedule-day-title"><input type="checkbox" name="dia_${v}" value="${v}" ${active?'checked':''} aria-label="Atiende ${n}"><label>${n}</label></div><div class="schedule-range"><span>Primer horario</span><label>Desde <input type="time" name="inicio_${v}_1" value="${a.inicio||'09:00'}" ${active?'':'disabled'}></label><label>Hasta <input type="time" name="fin_${v}_1" value="${a.fin||'12:00'}" ${active?'':'disabled'}></label></div><div class="schedule-range second-range" data-range="${v}" ${b.inicio?'':'hidden'}><span>Segundo horario</span><label>Desde <input type="time" name="inicio_${v}_2" value="${b.inicio||'15:00'}" ${active&&b.inicio?'':'disabled'}></label><label>Hasta <input type="time" name="fin_${v}_2" value="${b.fin||'18:00'}" ${active&&b.inicio?'':'disabled'}></label><button type="button" class="remove-shift" data-remove-shift="${v}" aria-label="Quitar segundo horario">Quitar</button></div><button type="button" class="add-shift" data-add-shift="${v}" ${active&&!b.inicio?'':'hidden'}>＋ Agregar segundo horario</button></div>`}).join('')}
function bindScheduleRows(){document.querySelectorAll('.schedule-day-multi').forEach(row=>{const ch=row.querySelector('input[type=checkbox]');ch.addEventListener('change',()=>{row.querySelectorAll('input[type=time]').forEach(i=>i.disabled=!ch.checked);row.querySelector('.add-shift').hidden=!ch.checked||!row.querySelector('.second-range').hidden});row.querySelector('[data-add-shift]').addEventListener('click',()=>{const box=row.querySelector('.second-range');box.hidden=false;box.querySelectorAll('input[type=time]').forEach(i=>i.disabled=false);row.querySelector('.add-shift').hidden=true});row.querySelector('[data-remove-shift]').addEventListener('click',()=>{const box=row.querySelector('.second-range');box.hidden=true;box.querySelectorAll('input[type=time]').forEach(i=>{i.disabled=true;i.value=''});row.querySelector('.add-shift').hidden=!ch.checked})})}
function schedulePayload(){const horarios=[];for(let day=1;day<=7;day++){const check=recordForm.querySelector(`[name="dia_${day}"]`);if(check?.checked){for(let shift=1;shift<=2;shift++){const ini=recordForm.elements[`inicio_${day}_${shift}`],fin=recordForm.elements[`fin_${day}_${shift}`];if(!ini||!fin||ini.disabled&&shift===2)continue;const inicio=ini.value,finValue=fin.value;if(!inicio||!finValue||inicio>=finValue)throw new Error(`Revisá el ${shift===1?'primer':'segundo'} horario del día seleccionado: el inicio debe ser anterior al cierre.`);horarios.push({dia:day,hora_inicio:inicio,hora_fin:finValue})}}}if(!horarios.length)throw new Error('Seleccioná al menos un día y horario de atención.');for(let i=0;i<horarios.length;i++)for(let j=i+1;j<horarios.length;j++)if(horarios[i].dia===horarios[j].dia&&horarios[i].hora_inicio<horarios[j].hora_fin&&horarios[j].hora_inicio<horarios[i].hora_fin)throw new Error('Los dos horarios de un mismo día no pueden superponerse.');return horarios}
document.getElementById('newPro').onclick=()=>{const specs=(currentAdminData?.specialties||[]).map(x=>`<label class="modal-field"><span><input type="checkbox" name="especialidad_ids" value="${x.id}"> ${safe(x.nombre)}</span></label>`).join('');openModal({title:'Agregar profesional',action:'agregar_profesional',submit:'Guardar profesional y agenda',fields:`<div class="modal-field"><label for="fNombre">Nombre y apellido *</label><input id="fNombre" name="nombre" required maxlength="150"></div><div class="modal-field"><label>Especialidades *</label><div>${specs||'Primero agregá una especialidad.'}</div></div><div class="modal-field"><label>Horario semanal por día *</label><p class="muted">Activá cada día y configurá su horario individual. Por ejemplo, lunes/miércoles/viernes de 12:00 a 18:00 y martes/jueves de 08:00 a 11:00.</p>${scheduleRows({'1':{activo:true,inicio:'12:00',fin:'18:00'},'2':{activo:true,inicio:'08:00',fin:'11:00'},'3':{activo:true,inicio:'12:00',fin:'18:00'},'4':{activo:true,inicio:'08:00',fin:'11:00'},'5':{activo:true,inicio:'12:00',fin:'18:00'}})}</div><div class="modal-field"><label for="fDuracion">Duración de cada consulta</label><select id="fDuracion" name="intervalo_minutos"><option value="15">15 minutos</option><option value="20">20 minutos</option><option value="30" selected>30 minutos</option><option value="45">45 minutos</option><option value="60">60 minutos</option></select></div>`});bindScheduleRows()};;
recordForm.addEventListener('submit',e=>{if(modalConfig?.action==='agregar_profesional'||modalConfig?.action==='editar_agenda_profesional'){e.preventDefault();e.stopImmediatePropagation();try{const name=recordForm.elements.nombre?.value.trim();const ids=[...recordForm.querySelectorAll('[name="especialidad_ids"]:checked')].map(x=>Number(x.value));const horarios=schedulePayload();const intervalo=Number(recordForm.elements.intervalo_minutos.value);const payload={id:modalConfig.id,nombre:name,especialidad_ids:ids,horarios,intervalo_minutos:intervalo};if(modalConfig.action==='agregar_profesional'&&(!name||!ids.length))throw new Error('Completá el nombre y elegí al menos una especialidad.');submitRecord(modalConfig.action,payload)}catch(err){const el=document.getElementById('modalError');el.textContent=err.message;el.classList.add('show')}}},true);
function confirmModal(title,message,confirmText,onConfirm){openModal({title,submit:confirmText,fields:`<p>${safe(message)}</p>`,action:'__confirm'});modalConfig.onConfirm=onConfirm}
// Rebind submit handling for confirm-only dialogs and record edits/deletes.
recordForm.addEventListener('submit',e=>{if(modalConfig?.action==='__confirm'){e.preventDefault();const fn=modalConfig.onConfirm;e.stopImmediatePropagation();closeModal();if(fn)fn()}},true);

document.getElementById('professionalsList').addEventListener('click',async event=>{const button=event.target.closest('.edit-professional');if(!button)return;try{const info=await api('agenda_profesional',{id:Number(button.dataset.id)});const specs=(currentAdminData?.specialties||[]).map(x=>`<label class="modal-field"><span><input type="checkbox" name="especialidad_ids" value="${x.id}" ${(info.especialidad_ids||[]).includes(Number(x.id))?'checked':''}> ${safe(x.nombre)}</span></label>`).join('');const initial={};(info.horarios||[]).forEach(h=>{const key=String(h.dia_semana);if(!initial[key])initial[key]=[];initial[key].push({inicio:h.hora_inicio,fin:h.hora_fin})});openModal({title:'Editar profesional y agenda',action:'editar_agenda_profesional',id:Number(button.dataset.id),submit:'Guardar cambios',fields:`<div class="modal-field"><label>Nombre y apellido</label><input name="nombre" value="${safe(info.nombre)}" required maxlength="150"></div><div class="modal-field"><label>Especialidades *</label><div>${specs}</div></div><div class="modal-field"><label>Horario semanal por día *</label><p class="muted">Cada día tiene su propio horario. Desactivá los días en los que no atiende.</p>${scheduleRows(initial)}</div><div class="modal-field"><label>Duración de cada consulta</label><select name="intervalo_minutos"><option value="15" ${Number(info.intervalo_minutos)===15?'selected':''}>15 minutos</option><option value="20" ${Number(info.intervalo_minutos)===20?'selected':''}>20 minutos</option><option value="30" ${Number(info.intervalo_minutos||30)===30?'selected':''}>30 minutos</option><option value="45" ${Number(info.intervalo_minutos)===45?'selected':''}>45 minutos</option><option value="60" ${Number(info.intervalo_minutos)===60?'selected':''}>60 minutos</option></select></div>`});bindScheduleRows()}catch(err){note(err.message)}});
document.getElementById('agendaInfo').onclick=()=>note('La agenda comprueba los solapamientos del profesional antes de registrar un turno.');

loadAdminData();
// Refresca los eventos reales periódicamente sin recargar la página completa.
setInterval(()=>{if(!document.hidden)loadAdminData()},10000);
