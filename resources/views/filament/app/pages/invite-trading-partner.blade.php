<x-filament-panels::page>
    <div class="card bg-base-100 shadow-xl max-w-xl">
        <div class="card-body gap-4">
            <p class="text-sm opacity-70">
                Partner gets a GLN record and an inbound HTTPS or SFTP route. Finish SFTP credentials on Inbound Connections.
            </p>

            <form wire:submit.prevent="mountAction('invitePartner')" class="flex flex-col gap-4">
                <label class="form-control gap-1">
                    <span class="label-text text-sm font-medium">Partner name</span>
                    <input type="text" wire:model="name" class="input input-bordered" required />
                </label>
                <label class="form-control gap-1">
                    <span class="label-text text-sm font-medium">GLN (13 digits)</span>
                    <input type="text" wire:model="gln" class="input input-bordered font-mono" maxlength="13" required />
                </label>
                <label class="form-control gap-1">
                    <span class="label-text text-sm font-medium">PoC email</span>
                    <input type="email" wire:model="email" class="input input-bordered" required />
                </label>
                <label class="form-control gap-1">
                    <span class="label-text text-sm font-medium">Inbound transport</span>
                    <select wire:model="transport" class="select select-bordered">
                        <option value="https">HTTPS</option>
                        <option value="sftp">SFTP</option>
                    </select>
                </label>
                <button type="submit" class="btn btn-primary min-h-12">Invite partner</button>
            </form>
        </div>
    </div>

    <x-filament-actions::modals />
</x-filament-panels::page>
