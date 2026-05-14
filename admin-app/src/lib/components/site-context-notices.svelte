<div class="sf:grid sf:gap-6 sf:lg:grid-cols-[minmax(0,2fr)_minmax(18rem,1fr)]" data-testid="site-context-notices">
	<section
		class="sf:rounded-xl sf:border sf:border-primary-100 sf:bg-primary-50 sf:p-6 sf:text-primary-700 sf:shadow-sm sf:sm:p-8"
	>
		<h3 class="sf:text-lg sf:font-semibold">High-sensitivity secret handling</h3>
		<p class="sf:mt-5 sf:text-base sf:leading-7">
			Saved provider keys are encrypted locally with server-side WordPress secrets. If you
			need stronger operational control, configure the provider through a WordPress
			constant or environment variable instead of storing the key in the database.
		</p>
		<p class="sf:mt-6 sf:text-base sf:leading-7">
			If WordPress salts change later, encrypted saved keys will need to be entered again.
		</p>
	</section>

	<section class="sf:rounded-xl sf:border sf:border-slate-200 sf:bg-white sf:p-6 sf:shadow-sm sf:sm:p-8">
		<h3 class="sf:text-lg sf:font-semibold sf:text-slate-950">OpenRouter privacy note</h3>
		<p class="sf:mt-5 sf:text-base sf:leading-7 sf:text-slate-600">
			Zero Data Retention depends on the specific OpenRouter route and upstream model
			provider. Many free routes have different retention or training policies, so review
			the provider privacy terms and the
			<a
				class="sf:font-medium sf:text-primary-700 sf:underline sf:underline-offset-2"
				href="https://openrouter.ai/docs/guides/features/zdr"
				target="_blank"
				rel="noreferrer"
			>
				OpenRouter ZDR guide
			</a>
			before using them on sensitive forms.
		</p>
	</section>
</div>
