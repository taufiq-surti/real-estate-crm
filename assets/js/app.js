(function () {
    const form = document.getElementById('projectLeadForm');
    if (!form) return;

    const statusEl = document.getElementById('formStatus');
    const successModal = document.getElementById('successModal');
    const closeModal = document.getElementById('closeModal');
    const submitUrl = form.dataset.submitUrl || '/api/submit_lead.php';

    closeModal?.addEventListener('click', function () {
        successModal.classList.add('hidden');
        successModal.classList.remove('flex');
    });

    form.addEventListener('submit', async function (event) {
        event.preventDefault();

        const mobile = (form.mobile.value || '').trim();
        if (!/^\d{10}$/.test(mobile)) {
            statusEl.textContent = 'Please enter a valid 10 digit mobile number.';
            statusEl.className = 'text-sm text-red-400';
            return;
        }

        statusEl.textContent = 'Submitting...';
        statusEl.className = 'text-sm text-slate-300';

        const payload = {
            name: form.name.value.trim(),
            mobile: mobile,
            email: form.email.value.trim(),
            city: form.city.value.trim(),
            message: form.message.value.trim(),
            project_id: form.dataset.projectId,
            form_token: form.dataset.token,
            form_name: form.form_name.value,
            trap: form.trap.value
        };

        try {
            const response = await fetch(submitUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });

            const result = await response.json();
            if (!response.ok || !result.success) {
                throw new Error(result.message || 'Submission failed.');
            }

            form.reset();
            statusEl.textContent = '';
            successModal.classList.remove('hidden');
            successModal.classList.add('flex');
        } catch (error) {
            statusEl.textContent = error.message || 'Something went wrong.';
            statusEl.className = 'text-sm text-red-400';
        }
    });
})();
